<?php

namespace App\Imports;

use App\Models\ExpedienteAcademico;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class MatriculaRegistroImport implements ToCollection, WithHeadingRow
{
    private int $epSedeId;
    private int $periodoId;

    private array $report = [
        'processed'           => 0,
        'created_users'       => 0,
        'updated_users'       => 0,
        'created_expedientes' => 0,
        'updated_expedientes' => 0,
        'created_matriculas'  => 0,
        'updated_matriculas'  => 0,
        'skipped'             => 0,
        'errors'              => 0,
    ];

    private array $rows = [];

    public function __construct(int $epSedeId, int $periodoId)
    {
        $this->epSedeId  = $epSedeId;
        $this->periodoId = $periodoId;
    }

    public function collection(Collection $rows)
    {
        // Normalizar encabezados por fila (snake_case sin tildes/nbspace)
        $rows = $rows->map(function ($row) {
            $norm = [];
            foreach ($row as $k => $v) {
                $k = $this->normalizeHeader($k);
                $norm[$k] = is_string($v) ? trim($v) : $v;
            }
            return $norm;
        });

        foreach ($rows as $i => $row) {
            $this->report['processed']++;

            try {
                DB::transaction(function () use ($row, $i) {
                    $parsed  = $this->parseRow($row);
                    $result  = $this->upsert($parsed);

                    $this->rows[] = [
                        'row'     => $i + 2,
                        'status'  => $result['status'],
                        'message' => $result['message'],
                        'ids'     => Arr::only($result, ['user_id','expediente_id','matricula_id']),
                        'data'    => array_merge(
                            Arr::only($parsed, [
                                'usuario','email','estudiante','documento','correo_institucional',
                                'codigo_estudiante','ciclo','grupo','modalidad_estudio','modo_contrato',
                                'fecha_matricula','fecha_matricula_raw','pais'
                            ]),
                            ['pais_iso2' => $parsed['pais'] ? $this->toIso2Country($parsed['pais']) : null]
                        ),
                    ];
                });
            } catch (\Throwable $e) {
                $this->report['errors']++;
                $this->rows[] = [
                    'row'     => $i + 2,
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                    'ids'     => null,
                    'data'    => null,
                ];
            }
        }
    }

    public function summary(): array { return $this->report; }
    public function rows(): array { return $this->rows; }

    // ========= Núcleo de negocio =========

    private function upsert(array $p): array
    {
        // 1) Expediente por código (si viene)
        $exp = null;
        if ($p['codigo_estudiante']) {
            $exp = ExpedienteAcademico::query()
                ->where('codigo_estudiante', $p['codigo_estudiante'])
                ->first();
        }

        $user = null;
        $createdUser = false;
        $epSedeMoved = false;

        if ($exp) {
            // Ya hay expediente → usar su usuario
            $user = $exp->user;

            // Si está en otra EP-SEDE, migrar
            if ($exp->ep_sede_id !== $this->epSedeId) {
                $exp->ep_sede_id = $this->epSedeId;
                $exp->save();
                $this->report['updated_expedientes']++;
                $epSedeMoved = true;
            }

            // Asegurar password/país si faltan
            $updated = false;
            if (empty($user->password)) {
                $this->ensurePassword($user, $p['documento'], false);
                $updated = true;
            }
            if ($p['pais'] && !$user->pais) {
                $user->pais = $this->toIso2Country($p['pais']);
                $updated = true;
            }
            if ($updated) {
                $user->save();
                $this->report['updated_users']++;
            }

            // Rol ESTUDIANTE
            $this->ensureStudentRole($user);

        } else {
            // Buscar o crear usuario
            $user = $this->findUser($p);
            if (!$user) {
                $user = new User();
                $createdUser = true;
            }

            // Nombres
            $name = $this->splitName($p['estudiante']);

            // Username si es nuevo
            if (!$user->exists) {
                $user->username = $this->username($p['usuario'], $p['documento']) ?? $this->usernameFromEmail($p['email']);
            }

            // Campos base
            $user->first_name = $name['first_name'] ?? ($user->first_name ?? 'N/A');
            $user->last_name  = $name['last_name']  ?? ($user->last_name  ?? 'N/A');

            if ($p['email'])                               $user->email = $p['email'];
            if (!$user->email && $p['correo_institucional']) $user->email = $p['correo_institucional'];
            if ($p['celular'])                             $user->celular = $p['celular'];
            if ($p['pais'])                                $user->pais = $this->toIso2Country($p['pais']);
            if ($p['religion'])                            $user->religion = $p['religion'];
            if ($p['fecha_nacimiento'])                    $user->fecha_nacimiento = $p['fecha_nacimiento'];

            if ($p['documento']) {
                $user->doc_numero = $p['documento'];
                $user->doc_tipo   = $this->docTipo($p['documento'], $user->pais);
            }

            // Estado del usuario según matrícula presente
            $user->status = $p['fecha_matricula'] ? 'active' : 'view_only';

            // Password garantizada
            $this->ensurePassword($user, $p['documento'], $createdUser);

            $user->save();
            $createdUser ? $this->report['created_users']++ : $this->report['updated_users']++;

            // Rol ESTUDIANTE
            $this->ensureStudentRole($user);

            // Crear expediente
            $exp = new ExpedienteAcademico();
            $exp->user_id               = $user->id;
            $exp->ep_sede_id            = $this->epSedeId;
            $exp->codigo_estudiante     = $p['codigo_estudiante'] ?: null;
            $exp->grupo                 = $p['grupo'] ?: null;
            $exp->correo_institucional  = $p['correo_institucional'] ?: null;
            $exp->estado                = $p['fecha_matricula'] ? 'ACTIVO' : 'SUSPENDIDO';
            $exp->rol                   = 'ESTUDIANTE';
            $exp->ciclo                 = $p['ciclo']; // string en expediente
            $exp->save();

            $this->report['created_expedientes']++;
        }

        // Actualizar expediente si cambió algo
        $expUpdated = false;
        $toUpdate = [];
        if ($p['grupo'] !== null && $p['grupo'] !== $exp->grupo) {
            $toUpdate['grupo'] = $p['grupo'];
        }
        if ($p['correo_institucional'] !== null && $p['correo_institucional'] !== $exp->correo_institucional) {
            $toUpdate['correo_institucional'] = $p['correo_institucional'];
        }
        if ($p['ciclo'] !== null && $p['ciclo'] !== $exp->ciclo) {
            $toUpdate['ciclo'] = $p['ciclo']; // string
        }
        $nuevoEstado = $p['fecha_matricula'] ? 'ACTIVO' : 'SUSPENDIDO';
        if ($exp->estado !== $nuevoEstado) {
            $toUpdate['estado'] = $nuevoEstado;
        }
        if ($toUpdate) {
            $exp->update($toUpdate);
            $this->report['updated_expedientes']++;
            $expUpdated = true;
        }

        // Crear/actualizar/anular Matrícula (una por período)
        $mat = Matricula::query()
            ->where('expediente_id', $exp->id)
            ->where('periodo_id', $this->periodoId)
            ->first();

        $tieneFecha = !empty($p['fecha_matricula']);

        if ($tieneFecha) {
            $payload = [
                'ciclo'             => $this->toCicloInt($p['ciclo']),
                'grupo'             => $p['grupo'] ?: null,
                'modalidad_estudio' => $this->normalizeModalidad($p['modalidad_estudio']),
                'modo_contrato'     => $this->normalizeContrato($p['modo_contrato']),
                'fecha_matricula'   => $p['fecha_matricula'], // Y-m-d
            ];

            if ($mat) {
                $mat->update($payload);
                $this->report['updated_matriculas']++;
                $matId = $mat->id;
                $statusMsg = 'matrícula actualizada';
            } else {
                $mat = new Matricula();
                $mat->expediente_id = $exp->id;
                $mat->periodo_id    = $this->periodoId;
                foreach ($payload as $k => $v) $mat->{$k} = $v;
                $mat->save();

                $this->report['created_matriculas']++;
                $matId = $mat->id;
                $statusMsg = 'matrícula creada';
            }
        } else {
            // Sin fecha → no crear; si existe, anular (fecha null)
            $matId = null;
            if ($mat) {
                $changes = [
                    'ciclo'             => $this->toCicloInt($p['ciclo']),
                    'grupo'             => $p['grupo'] ?: null,
                    'modalidad_estudio' => $this->normalizeModalidad($p['modalidad_estudio']),
                    'modo_contrato'     => $this->normalizeContrato($p['modo_contrato']),
                    'fecha_matricula'   => null,
                ];
                $mat->update($changes);
                $this->report['updated_matriculas']++;
                $matId = $mat->id;
                $statusMsg = 'matrícula anulada (sin fecha)';
            } else {
                $statusMsg = 'sin matrícula (no creada por falta de fecha)';
            }
        }

        if ($epSedeMoved && $expUpdated) {
            $statusMsg .= ' + expediente actualizado + EP-SEDE cambiada';
        } elseif ($epSedeMoved) {
            $statusMsg .= ' + EP-SEDE cambiada';
        } elseif ($expUpdated) {
            $statusMsg .= ' + expediente actualizado';
        }

        // Recalcular vigencias
        $this->refreshVigencias($exp);

        return [
            'status'        => 'ok',
            'message'       => $statusMsg,
            'user_id'       => $exp->user_id,
            'expediente_id' => $exp->id,
            'matricula_id'  => $matId,
        ];
    }

    // ========= Parseo / utilidades =========

    private function normalizeHeader(string $h): string
    {
        $h = (string) $h;
        $h = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $h); // NBSP/NNBSP
        $h = mb_strtolower($h, 'UTF-8');
        $h = $this->unaccent($h);
        $h = str_replace(['.', ',', ';'], ' ', $h);
        $h = str_replace(["'", "’", "`", "´"], '', $h);
        $h = preg_replace('/\s+/', ' ', $h);
        $h = trim($h);
        $h = str_replace(' ', '_', $h);
        return $h;
    }

    private function firstNotNull(array $row, array $keys): mixed
    {
        foreach ($keys as $k) {
            $k = $this->normalizeHeader($k);
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                return $row[$k];
            }
        }
        return null;
    }

    private function parseRow(array $row): array
    {
        $map = [
            'modo_contrato'        => ['modo_contrato','modo contrato'],
            'modalidad_estudio'    => ['modalidad_estudio','modalidad estudio'],
            'ciclo'                => ['ciclo'],
            'grupo'                => ['grupo'],
            'codigo_estudiante'    => ['codigo_estudiante','código estudiante','codigo estudiante','codigo','código'],
            'estudiante'           => ['estudiante','alumno','nombre completo','apellidos y nombres','nombres y apellidos'],
            'documento'            => ['documento','dni','doc','nro documento','numero documento','número documento'],
            'email'                => ['correo','email','correo personal','correo electronico','correo electrónico'],
            'usuario'              => ['usuario','username','user'],
            'correo_institucional' => ['correo_institucional','correo institucional','email institucional'],
            'celular'              => ['celular','telefono','teléfono','whatsapp','telefono celular','teléfono celular','cel'],
            'pais'                 => ['pais','país','country','nacionalidad'],
            'religion'             => ['religion','religión'],
            'fecha_nacimiento'     => ['fecha de nacimiento','fecha_nacimiento','nacimiento','f. nacimiento','fec. nacimiento'],
            'fecha_matricula'      => ['fecha de matrícula','fecha de matricula','fecha_matricula','matricula','matrícula'],
        ];

        $out = [];
        foreach ($map as $key => $aliases) {
            $out[$key] = $this->firstNotNull($row, $aliases);
        }

        // Guardar raw y normalizar fechas
        $out['fecha_matricula_raw'] = $out['fecha_matricula'];
        $out['fecha_nacimiento'] = $this->parseDate($out['fecha_nacimiento']);
        $out['fecha_matricula']  = $this->parseDate($out['fecha_matricula']);

        // Strings “limpios”
        foreach ([
            'modo_contrato','modalidad_estudio','ciclo','grupo','codigo_estudiante','estudiante',
            'documento','email','usuario','correo_institucional','celular','pais','religion'
        ] as $k) {
            if (isset($out[$k]) && is_string($out[$k])) {
                $out[$k] = trim($out[$k]);
                if ($out[$k] === '') $out[$k] = null;
            }
        }

        return $out;
    }

    /**
     * Acepta:
     *  - Números Excel (con hora) -> Y-m-d
     *  - Cadenas con AM/PM español o 24h
     */
    private function parseDate($value): ?string
    {
        if (!$value) return null;

        if (is_numeric($value)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject($value);
                return $dt->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (is_string($value)) {
            $v = trim($value);
            $v = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $v);
            $v = preg_replace('/\s+/', ' ', $v);

            // "a. m." / "p. m." -> AM/PM
            $v = preg_replace_callback('/\b([ap])\s*\.?\s*m\.?\b/iu', function ($m) {
                return strtoupper($m[1]).'M';
            }, $v);

            $formats = [
                'd/m/Y h:i:s A', 'd-m-Y h:i:s A', 'm/d/Y h:i:s A',
                'd/m/Y h:i A',   'd-m-Y h:i A',   'm/d/Y h:i A',
                'd/m/Y H:i:s',   'd-m-Y H:i:s',   'm/d/Y H:i:s',
                'd/m/Y H:i',     'd-m-Y H:i',     'm/d/Y H:i',
                'Y-m-d H:i:s',   'Y-m-d H:i',     'Y-m-d',
                'd/m/Y',         'd-m-Y',         'm/d/Y',
            ];

            foreach ($formats as $fmt) {
                $dt = \DateTime::createFromFormat($fmt, $v);
                if ($dt instanceof \DateTime) {
                    $errs = \DateTime::getLastErrors();
                    if (!is_array($errs) || ($errs['warning_count'] === 0 && $errs['error_count'] === 0)) {
                        return $dt->format('Y-m-d');
                    }
                }
            }

            if (preg_match('/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $v, $m)) {
                return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
            }
            if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', $v, $m)) {
                $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
                if ($y < 100) $y += ($y >= 70 ? 1900 : 2000);
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        return null;
    }

    // ========= splitName corregido (2 primeros = apellidos, resto = nombres) =========
    private function splitName(?string $full): array
    {
        $full = trim((string)$full);
        if ($full === '') {
            return ['first_name' => 'N/A', 'last_name' => 'N/A'];
        }

        // Normalizar espacios
        $full = preg_replace('/\s+/', ' ', $full);

        // Caso con coma: "APELLIDOS, NOMBRES"
        if (str_contains($full, ',')) {
            [$left, $right] = array_map('trim', explode(',', $full, 2));
            $last  = $left ?: 'N/A';
            $first = $right ?: 'N/A';

            return [
                'first_name' => $first,
                'last_name'  => $last,
            ];
        }

        // Sin coma → separar por espacios
        $parts = array_values(array_filter(explode(' ', $full), fn($p) => $p !== ''));
        $count = count($parts);

        if ($count === 1) {
            return [
                'first_name' => $parts[0],
                'last_name'  => 'N/A',
            ];
        }

        if ($count === 2) {
            // Heurística simple: "APELLIDO NOMBRE"
            return [
                'first_name' => $parts[1],
                'last_name'  => $parts[0],
            ];
        }

        // Heurística hispana: los 2 primeros son apellidos, el resto son nombres
        $last  = $parts[0].' '.$parts[1];
        $first = implode(' ', array_slice($parts, 2));

        return [
            'first_name' => $first,
            'last_name'  => $last,
        ];
    }

    private function username(?string $usuario, ?string $documento): string
    {
        $base = $usuario ?: ($documento ?: Str::random(6));
        $slug = Str::of($base)->lower()->replace(' ', '.')->replace(['_', ',',';'], '.');
        $u = (string) $slug;
        $n = 1;
        while (User::where('username', $u)->exists()) {
            $u = $slug.'+'.(++$n);
        }
        return $u;
    }

    private function usernameFromEmail(?string $email): string
    {
        $u = $email && str_contains($email, '@') ? strstr($email, '@', true) : Str::random(6);
        $u = Str::of($u)->lower()->replace([' ', '_'], '.');
        $base = (string)$u;
        $n = 1;
        while (User::where('username', $u)->exists()) {
            $u = $base.'+'.(++$n);
        }
        return $u;
    }

    private function docTipo(?string $doc, ?string $pais): ?string
    {
        if (!$doc) return null;
        $raw = preg_replace('/[\s\-.]/', '', (string)$doc);
        $hasLetters = (bool) preg_match('/[A-Za-z]/', $raw);
        $len = strlen($raw);
        $pais = strtoupper((string) $pais);

        if (!$hasLetters && $len === 8 && in_array($pais, ['PE','PERU','PERÚ',''])) {
            return 'DNI';
        }
        if (!$hasLetters && $len >= 9 && $len <= 12) {
            return 'CE';
        }
        if ($hasLetters && $len >= 6 && $len <= 12) {
            return 'PASAPORTE';
        }
        return 'OTRO';
    }

    /** Normaliza pais a ISO-2 (ej.: "Perú" -> "PE"). */
    private function toIso2Country(?string $val): ?string
    {
        if (!$val) return null;

        $v = trim($val);
        $v = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v);
        $v = trim($v);

        $vUp = mb_strtoupper($v, 'UTF-8');
        $vUp = $this->unaccent($vUp);
        $vUp = preg_replace('/\s+/', ' ', $vUp);
        $vUp = trim($vUp);

        if (preg_match('/^[A-Z]{2}$/', $vUp)) return $vUp;

        $key = str_replace(' ', '', $vUp);

        $iso3 = [
            'PER'=>'PE','MEX'=>'MX','COL'=>'CO','CHL'=>'CL','ARG'=>'AR','ECU'=>'EC','ESP'=>'ES',
            'USA'=>'US','URY'=>'UY','PRY'=>'PY','BOL'=>'BO','VEN'=>'VE','BRA'=>'BR','CAN'=>'CA',
            'DEU'=>'DE','FRA'=>'FR','ITA'=>'IT','PRT'=>'PT','GBR'=>'GB','CHN'=>'CN','JPN'=>'JP',
            'PAN'=>'PA','CRI'=>'CR','HND'=>'HN','GTM'=>'GT','NIC'=>'NI','SLV'=>'SV','DOM'=>'DO','PRI'=>'PR'
        ];
        if (preg_match('/^[A-Z]{3}$/', $key) && isset($iso3[$key])) return $iso3[$key];

        $names = [
            'PERU' => 'PE', 'MEXICO' => 'MX', 'COLOMBIA' => 'CO', 'CHILE' => 'CL', 'ARGENTINA' => 'AR',
            'ECUADOR' => 'EC', 'ESPANA' => 'ES', 'SPAIN' => 'ES',
            'ESTADOSUNIDOS' => 'US', 'EEUU' => 'US', 'USA' => 'US',
            'REINOUNIDO' => 'GB', 'UK' => 'GB', 'INGLATERRA' => 'GB',
            'BRASIL' => 'BR', 'BRAZIL' => 'BR', 'BOLIVIA' => 'BO', 'VENEZUELA' => 'VE',
            'URUGUAY' => 'UY', 'PARAGUAY' => 'PY', 'PANAMA' => 'PA', 'COSTARICA' => 'CR',
            'HONDURAS' => 'HN', 'GUATEMALA' => 'GT', 'NICARAGUA' => 'NI',
            'ELSALVADOR' => 'SV', 'REPUBLICADOMINICANA' => 'DO', 'DOMINICANA' => 'DO',
            'PUERTORICO' => 'PR', 'CANADA' => 'CA', 'ALEMANIA' => 'DE', 'FRANCIA' => 'FR',
            'ITALIA' => 'IT', 'PORTUGAL' => 'PT', 'CHINA' => 'CN', 'JAPON' => 'JP', 'JAPAN' => 'JP',
        ];

        return $names[$key] ?? null;
    }

    private function toCicloInt(?string $ciclo): ?int
    {
        if ($ciclo === null) return null;
        $c = (int) preg_replace('/\D+/', '', (string)$ciclo);
        return $c > 0 ? $c : null;
    }

    private function normalizeModalidad(?string $v): ?string
    {
        if (!$v) return null;
        $v = strtoupper(trim($v));
        $v = str_replace(['Á','É','Í','Ó','Ú'], ['A','E','I','O','U'], $v);
        if (str_starts_with($v, 'PRE')) return 'PRESENCIAL';
        if (str_starts_with($v, 'SEM') || str_contains($v, 'MIX') || str_contains($v, 'BLE')) return 'SEMIPRESENCIAL';
        if (str_starts_with($v, 'VIR') || str_contains($v, 'ON')) return 'VIRTUAL';
        return null;
    }

    private function normalizeContrato(?string $v): ?string
    {
        if (!$v) return null;
        $v = strtoupper(trim($v));
        $v = str_replace(['Á','É','Í','Ó','Ú'], ['A','E','I','O','U'], $v);
        if (str_contains($v, 'REG')) return 'REGULAR';
        if (str_contains($v, 'CONV')) return 'CONVENIO';
        if (str_contains($v, 'BECA')) return 'BECA';
        return 'OTRO';
    }

    private function findUser(array $p): ?User
    {
        if ($p['documento']) {
            $u = User::where('doc_numero', $p['documento'])->first();
            if ($u) return $u;
        }
        if ($p['usuario']) {
            $u = User::where('username', $p['usuario'])->first();
            if ($u) return $u;
        }
        if ($p['email']) {
            $u = User::where('email', $p['email'])->first();
            if ($u) return $u;
        }
        return null;
    }

    private function unaccent(string $s): string
    {
        return strtr($s, [
            'Á'=>'A','À'=>'A','Â'=>'A','Ä'=>'A','Ã'=>'A','Å'=>'A','Æ'=>'AE',
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
            'Ó'=>'O','Ò'=>'O','Ô'=>'O','Ö'=>'O','Õ'=>'O','Ø'=>'O',
            'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
            'Ñ'=>'N','Ç'=>'C',
            'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','æ'=>'ae',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ñ'=>'n','ç'=>'c',
        ]);
    }

    /** Garantiza que el usuario tenga contraseña antes de guardar. */
    private function ensurePassword(User $user, ?string $documento, bool $createdUser): void
    {
        if (!empty($user->password)) {
            return;
        }
        $rounds = (int) env('BCRYPT_ROUNDS', 10);
        $seed   = $documento ?: Str::random(10);
        $user->password = Hash::make((string) $seed, ['rounds' => $rounds]);
    }

    /** Asigna (si falta) el rol Spatie ESTUDIANTE. */
    private function ensureStudentRole(User $user): void
    {
        try {
            if (!$user->hasRole('ESTUDIANTE')) {
                $user->assignRole('ESTUDIANTE'); // Spatie resuelve el guard internamente
            }
        } catch (\Throwable $e) {
            logger()->warning(
                'No se pudo asignar rol ESTUDIANTE al usuario ID '.$user->id.': '.$e->getMessage()
            );
        }
    }

    /** Recalcula vigente_desde / vigente_hasta del expediente. */
    private function refreshVigencias(ExpedienteAcademico $exp): void
    {
        $min = Matricula::where('expediente_id', $exp->id)
                ->whereNotNull('fecha_matricula')->min('fecha_matricula');
        $max = Matricula::where('expediente_id', $exp->id)
                ->whereNotNull('fecha_matricula')->max('fecha_matricula');

        $upd = [];
        if ($exp->vigente_desde !== $min) $upd['vigente_desde'] = $min;
        if ($exp->vigente_hasta !== $max) $upd['vigente_hasta'] = $max;

        if ($upd) {
            $exp->fill($upd)->save();
        }
    }
}
