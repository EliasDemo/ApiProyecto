<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Models\ExpedienteAcademico;
use App\Models\Matricula;
use App\Models\PeriodoAcademico;
use App\Models\VmProyecto;
use App\Models\VmProceso;
use App\Models\VmSesion;
use App\Models\VmParticipacion;
use App\Models\VmAsistencia;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportHorasHistoricasController extends Controller
{
    // ========================= Auth / Scope =========================
    private function requireAuth(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(response()->json(['ok'=>false,'message'=>'No autenticado.'], 401));
        if (!($actor->can('ep.manage.ep_sede') || $actor->can('vm.manage'))) {
            abort(response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403));
        }
        return $actor;
    }

    private function resolverEpSedeIdOrFail($actor, ?int $epSedeId = null): int
    {
        if ($epSedeId) {
            if (!EpScopeService::userManagesEpSede($actor->id, $epSedeId)) {
                abort(response()->json(['ok'=>false,'message'=>'No autorizado para esa EP_SEDE.'], 403));
            }
            return (int)$epSedeId;
        }
        $managed = EpScopeService::epSedesIdsManagedBy($actor->id);
        if (count($managed) === 1) return (int)$managed[0];
        if (count($managed) > 1) {
            abort(response()->json([
                'ok'=>false,'message'=>'Administras más de una EP_SEDE. Envía ep_sede_id.','choices'=>$managed
            ], 422));
        }
        abort(response()->json(['ok'=>false,'message'=>'No administras ninguna EP_SEDE activa.'], 403));
    }

    // ========================= Endpoint =========================
    public function import(Request $request)
    {
        $actor = $this->requireAuth($request);

        $v = Validator::make($request->all(), [
            'file'       => ['required','file','mimes:xls,xlsx,csv','max:20480'],
            'ep_sede_id' => ['nullable','integer','exists:ep_sede,id'],
            'replace'    => ['nullable','boolean'],
        ], [], ['file'=>'archivo Excel']);
        if ($v->fails()) return response()->json(['ok'=>false,'errors'=>$v->errors()], 422);

        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));
        $replace  = $request->boolean('replace', false);
        $batch    = (string) Str::uuid();

        // Período en curso (nunca crear nada ahí)
        $perActual = $this->getPeriodoActual();
        $perActualCodigo = $perActual?->codigo;

        // Leer archivo
        try {
            $rows = $this->readAndNormalize($request->file('file'));
        } catch (\Throwable $e) {
            return response()->json(['ok'=>false,'message'=>'No se pudo leer el archivo.','error'=>$e->getMessage()], 400);
        }

        $errors    = [];
        $processed = 0;
        $asisCount = 0;
        $targets   = []; // set de (PER|NIVEL)

        // ========= PASADA 1: provisionar infra y recolectar targets =========
        $infraCache = []; // "PER|NIV" => ['proyecto_id','proceso_id','sesion_ids'=>[], 'periodo_id'=>...]
        foreach ($rows as $ri => $row) {
            $codigo = trim((string)($row['codigo'] ?? ''));
            if ($codigo === '') { $errors[] = ['row'=>$ri+2,'reason'=>'CODIGO_VACIO']; continue; }

            [$exp, $nfMeta] = $this->resolveExpedienteSmart($epSedeId, $codigo);
            if (!$exp) {
                $err = ['row'=>$ri+2,'codigo'=>$codigo,'reason'=>'CODIGO_NO_ENCONTRADO'];
                if ($nfMeta) $err += $nfMeta;
                $errors[] = $err;
                continue;
            }

            $tuvo = false;

            foreach ($this->iterPeriodCells($row) as [$kind,$label,$min]) {
                if ($kind === 'PERIODO') {
                    $per = $this->toPeriodoCodigo($label);
                    if ($perActualCodigo && $per === $perActualCodigo) continue; // no tocar en curso
                    $nivel = $this->inferCicloEnPeriodo($exp, $per) ?? 1;
                    $key   = $per.'|'.$nivel;

                    if (!isset($infraCache[$key])) {
                        $infra = $this->ensureInfraProyectoNivel($epSedeId, $per, $nivel);
                        $infraCache[$key] = $infra;
                        $targets[$key] = true;
                    }
                    $tuvo = true;

                } else { // ANTES
                    [$anchorPer] = $this->anchorPeriodoAntesDe($label); // soporta ANTES_DE_2024 y ANTES_DE_2024-1/2
                    if ($perActualCodigo && $anchorPer === $perActualCodigo) continue;

                    // K = ciclos existentes antes del ancla
                    $k = ($this->inferCicloEnPeriodo($exp, $anchorPer) ?? 1) - 1;
                    if ($k <= 0) continue;

                    $totalHoras = (int) round(((int)$min) / 60);

                    // Si totalHoras<=0 igual provisiona proyectos 1..k con 0 h
                    $prevs = $this->previousPeriods($anchorPer, $k);
                    foreach ($prevs as $idx => $perPrev) {
                        if ($perActualCodigo && $perPrev === $perActualCodigo) continue;
                        $nivel = $this->nivelFromAnchor($perPrev, $anchorPer, $k);
                        $key   = $perPrev.'|'.$nivel;
                        if (!isset($infraCache[$key])) {
                            $infraCache[$key] = $this->ensureInfraProyectoNivel($epSedeId, $perPrev, $nivel);
                            $targets[$key] = true;
                        }
                        $tuvo = true;
                    }
                }
            }

            if ($tuvo) $processed++;
        }

        // ========= Limpieza si replace=true =========
        if ($replace && !empty($targets)) {
            // Periodos tocados en esta importación
            $perCodes = [];
            foreach (array_keys($targets) as $key) {
                [$perCode, $nivel] = explode('|', $key);
                $perCodes[] = $perCode;
            }
            $perCodes = array_values(array_unique($perCodes));

            $perIds = PeriodoAcademico::whereIn('codigo', $perCodes)->pluck('id')->all();

            if (!empty($perIds)) {
                // Proyectos históricos vinculados y libres en esos períodos
                $projIds = VmProyecto::where('ep_sede_id', $epSedeId)
                    ->whereIn('periodo_id', $perIds)
                    ->where(function($q) {
                        $q->where('codigo', 'like', 'HIST-%')
                          ->orWhere('codigo', 'like', 'HIST-LIBRE-%')
                          ->orWhere('codigo', 'like', 'HIST_LIBRE-%');
                    })
                    ->pluck('id')
                    ->all();

                if (!empty($projIds)) {
                    $procIds = VmProceso::whereIn('proyecto_id', $projIds)->pluck('id')->all();

                    if (!empty($procIds)) {
                        $touchedSesionIds = VmSesion::whereIn('proceso_id', $procIds)->pluck('id')->all();

                        if (!empty($touchedSesionIds)) {
                            // Borrar primero registros de horas ligados a asistencias importadas
                            $aids = VmAsistencia::whereIn('sesion_id', $touchedSesionIds)
                                ->whereJsonContains('meta->source', 'horas_import')
                                ->pluck('id')
                                ->all();

                            if (!empty($aids)) {
                                DB::table('registro_horas')
                                    ->whereIn('asistencia_id', $aids)
                                    ->delete();
                            }

                            // Luego borrar dichas asistencias
                            VmAsistencia::whereIn('sesion_id', $touchedSesionIds)
                                ->whereJsonContains('meta->source', 'horas_import')
                                ->delete();
                        }
                    }
                }
            }
        }

        // ========= PASADA 2: inscribir y crear asistencias/horas (vinculado + libres) =========
        $infraLibreCache = []; // "PER|NIV|PACK" => infra proyecto libre

        foreach ($rows as $ri => $row) {
            $codigo = trim((string)($row['codigo'] ?? ''));
            [$exp]  = $this->resolveExpedienteSmart($epSedeId, $codigo);
            if (!$exp) continue;

            // Mapa de horas totales por (periodo, nivel) para ESTE alumno
            // sin cap de 5; eso se aplica después al repartir entre vinculado/libre.
            $horasPorPerNivel = []; // [per => [nivel => horasTotales]]

            foreach ($this->iterPeriodCells($row) as [$kind,$label,$min]) {
                if ($kind === 'PERIODO') {
                    $per = $this->toPeriodoCodigo($label);
                    if ($perActualCodigo && $per === $perActualCodigo) continue;

                    $nivel = $this->inferCicloEnPeriodo($exp, $per) ?? 1;
                    $horas = (int) round(((int)$min) / 60);
                    if ($horas <= 0) continue;

                    if (!isset($horasPorPerNivel[$per])) {
                        $horasPorPerNivel[$per] = [];
                    }
                    $horasPorPerNivel[$per][$nivel] = ($horasPorPerNivel[$per][$nivel] ?? 0) + $horas;

                } else { // ANTES
                    [$anchorPer] = $this->anchorPeriodoAntesDe($label);
                    if ($perActualCodigo && $anchorPer === $perActualCodigo) continue;

                    $k = ($this->inferCicloEnPeriodo($exp, $anchorPer) ?? 1) - 1;
                    if ($k <= 0) continue;

                    $totalHorasAntes = (int) round(((int)$min) / 60);
                    if ($totalHorasAntes <= 0) continue;

                    $assign = $this->distribuirHorasEnterasAntesDe($codigo, $anchorPer, $totalHorasAntes, $k);

                    foreach ($this->previousPeriods($anchorPer, $k) as $perPrev) {
                        if ($perActualCodigo && $perPrev === $perActualCodigo) continue;

                        $nivel = $this->nivelFromAnchor($perPrev, $anchorPer, $k);
                        $h     = (int) ($assign[$perPrev] ?? 0);
                        if ($h <= 0) {
                            continue;
                        }

                        if (!isset($horasPorPerNivel[$perPrev])) {
                            $horasPorPerNivel[$perPrev] = [];
                        }
                        $horasPorPerNivel[$perPrev][$nivel] = ($horasPorPerNivel[$perPrev][$nivel] ?? 0) + $h;
                    }
                }
            }

            if (empty($horasPorPerNivel)) {
                continue;
            }

            foreach ($horasPorPerNivel as $per => $byNivel) {
                if ($perActualCodigo && $per === $perActualCodigo) continue;

                foreach ($byNivel as $nivel => $totalHoras) {
                    $totalHoras = max(0, (int)$totalHoras);
                    if ($totalHoras === 0) {
                        continue;
                    }

                    $key = $per.'|'.$nivel;

                    // Asegurar infraestructura VINCULADO
                    if (!isset($infraCache[$key])) {
                        $infraCache[$key] = $this->ensureInfraProyectoNivel($epSedeId, $per, $nivel);
                        $targets[$key] = true;
                    }

                    // Hasta 5h se van al proyecto VINCULADO histórico
                    $horasVinc   = min(5, $totalHoras);
                    $horasLibres = max(0, $totalHoras - $horasVinc);

                    if ($horasVinc > 0) {
                        $asisCount += $this->inscribirYMarcarHoras(
                            $infraCache[$key],
                            $exp,
                            $codigo,
                            $per,
                            $nivel,
                            $horasVinc,
                            $batch
                        );
                    }

                    // El resto de horas va a proyectos LIBRES (en packs de 5h) en períodos CERRADOS
                    if ($horasLibres > 0) {
                        $asisCount += $this->inscribirHorasEnProyectosLibres(
                            $epSedeId,
                            $infraLibreCache,
                            $exp,
                            $codigo,
                            $per,
                            $nivel,
                            $horasLibres,
                            $batch
                        );
                    }
                }
            }
        }

        return response()->json([
            'ok' => true,
            'summary' => [
                'processed_rows'       => $processed,
                'targets'              => count($targets),
                'asistencias_upserted' => $asisCount,
                'errors'               => count($errors),
            ],
            'errors' => $errors,
        ], 200);
    }

    // ========================= Lectura & normalización =========================

    private function readAndNormalize($uploadedFile): array
    {
        $path = $uploadedFile->getRealPath();

        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $sheet = $spreadsheet->getSheet(0)->toArray(null, true, true, false);
        if (!is_array($sheet) || count($sheet) < 2) return [];

        $rawHead = array_map(fn($v)=>is_string($v)?$v: (string)$v, $sheet[0]);
        $heads   = array_map([$this,'normalizeHeader'], $rawHead);

        $rows = [];
        for ($i=1; $i<count($sheet); $i++) {
            $row = $sheet[$i];
            if (!is_array($row)) continue;

            $assoc = [];
            foreach ($heads as $idx => $key) {
                $val = $row[$idx] ?? null;
                if ($key === '') continue;
                $assoc[$key] = $val;
            }

            $codigo = trim((string)(
                $assoc['CODIGO']
                ?? $assoc['COD_ESTUDIANTE']
                ?? $assoc['CODIGO_ESTUDIANTE']
                ?? $assoc['CODE']
                ?? $assoc['COD']
                ?? ''
            ));

            $out = ['codigo' => $codigo];

            foreach ($assoc as $k => $v) {
                if (in_array($k, ['CODIGO','COD_ESTUDIANTE','CODIGO_ESTUDIANTE','CODE','COD'], true)) continue;

                if ($this->isPeriodoCol($k)) {
                    $min = $this->toMinutes($v);
                    if ($min !== 0) $out[$this->toPeriodoCodigo($k)] = $min;
                } elseif ($this->isAntesCol($k)) {
                    $min = $this->toMinutes($v);
                    if ($min !== 0) $out[$this->toAntesCodigo($k)] = $min;
                }
            }

            if ($out['codigo'] !== '' && count($out) > 1) $rows[] = $out;
        }
        return $rows;
    }

    private function normalizeHeader(string $h): string
    {
        $h = str_replace(["\xC2\xA0","\xE2\x80\xAF"], ' ', $h);
        $h = trim($h);
        $h = mb_strtoupper($h, 'UTF-8');
        $h = strtr($h, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','/'=>' ','\\'=>' ']);
        $h = preg_replace('/\s+/', ' ', $h);
        $h = str_replace(' ', '_', $h);
        return $h;
    }

    private function isPeriodoCol(string $k): bool
    {
        return (bool) preg_match('/^\d{4}[-_][12]$/', $k);
    }

    private function isAntesCol(string $k): bool
    {
        // Soportar ANTES_DE_2024 y ANTES_DE_2024-1 / ANTES_DE_2024_1
        return str_starts_with($k, 'ANTES_DE_') || str_starts_with($k, 'ANTESDE_');
    }

    private function toPeriodoCodigo(string $k): string
    {
        $k = str_replace('_','-',$k);
        return strtoupper($k);
    }

    private function toAntesCodigo(string $k): string
    {
        $k = str_replace('ANTESDE_','ANTES_DE_', $k);
        return strtoupper($k);
    }

    private function toMinutes($val): int
    {
        if ($val === null || $val === '') return 0;
        if (is_string($val)) $val = str_replace([','], ['.'], trim($val));
        if (!is_numeric($val)) return 0;
        $h = (float)$val;
        return (int) round($h * 60);
    }

    private function iterPeriodCells(array $row): \Generator
    {
        foreach ($row as $k => $v) {
            if ($k === 'codigo') continue;
            if ($this->isPeriodoCol($k)) {
                yield ['PERIODO', $this->toPeriodoCodigo($k), (int)$v];
            } elseif ($this->isAntesCol($k)) {
                yield ['ANTES', $this->toAntesCodigo($k), (int)$v];
            }
        }
    }

    // ========================= Periodos / Ciclos helpers =========================

    private function getPeriodoActual(): ?PeriodoAcademico
    {
        $per = PeriodoAcademico::where('es_actual',1)->first();
        if ($per) return $per;
        return PeriodoAcademico::where('estado','EN_CURSO')->orderByDesc('anio')->orderByDesc('ciclo')->first();
    }

    /** ANTES_DE_2024 o ANTES_DE_2024-1 => ['YYYY-1|2', YYYY, ciclo] */
    private function anchorPeriodoAntesDe(string $label): array
    {
        if (preg_match('/ANTES_DE_(\d{4})[-_]?([12])/', $label, $m)) {
            $y = (int)$m[1]; $c = (int)$m[2];
            return [sprintf('%04d-%d', $y, $c), $y, $c];
        }
        if (preg_match('/ANTES_DE_(\d{4})/', $label, $m)) {
            $y = (int)$m[1];
            return [sprintf('%04d-1', $y), $y, 1]; // por defecto YYYY-1
        }
        return [date('Y').'-1', (int)date('Y'), 1];
    }

    private function splitPeriodo(string $codigo): array
    {
        $codigo = str_replace('_','-',$codigo);
        [$y,$c] = explode('-', $codigo);
        return [(int)$y, (int)$c];
    }

    private function perOrd(int $y, int $c): int { return $y*2 + ($c===1?0:1); }

    private function perNext(string $per): string
    {
        [$y,$c] = $this->splitPeriodo($per);
        if ($c===1) return sprintf('%04d-2',$y);
        return sprintf('%04d-1', $y+1);
    }

    private function perPrev(string $per): string
    {
        [$y,$c] = $this->splitPeriodo($per);
        if ($c===2) return sprintf('%04d-1',$y);
        return sprintf('%04d-2', $y-1);
    }

    private function previousPeriods(string $anchor, int $k): array
    {
        $out = [];
        $cur = $anchor;
        for ($i=1;$i<=$k;$i++) {
            $cur = $this->perPrev($cur);
            $out[] = $cur;
        }
        // del más antiguo al más reciente
        return array_reverse($out);
    }

    private function nivelFromAnchor(string $perPrev, string $anchorPer, int $k): int
    {
        $list = $this->previousPeriods($anchorPer, $k); // antiguo..reciente
        $i = array_search($perPrev, $list, true);
        return $i === false ? 1 : ($i + 1); // niveles 1..k
    }

    private function toIntOrNull($v): ?int
    {
        if ($v===null) return null;
        if (is_numeric($v)) return (int)$v;
        $d = preg_replace('/\D+/','',(string)$v);
        return $d !== '' ? (int)$d : null;
    }

    private function inferCicloEnPeriodo(ExpedienteAcademico $exp, string $periodoCodigo): ?int
    {
        [$y,$c] = $this->splitPeriodo($periodoCodigo);
        $per = PeriodoAcademico::where('anio',$y)->where('ciclo',$c)->first();
        $mat = $per
            ? Matricula::where('expediente_id',$exp->id)->where('periodo_id',$per->id)->first()
            : null;
        if ($mat && $mat->ciclo) return $this->toIntOrNull($mat->ciclo);

        // Extrapolación desde matrículas
        $ref = Matricula::where('expediente_id',$exp->id)
            ->join('periodos_academicos as p','p.id','=','matriculas.periodo_id')
            ->orderByRaw('(p.anio*2 + p.ciclo) asc')
            ->select('matriculas.ciclo','p.anio','p.ciclo as pciclo')
            ->get();
        if ($ref->count() > 0) {
            $ordTarget = $this->perOrd($y,$c);
            $best = null; $bestDiff = 1e9;
            foreach ($ref as $r) {
                $ord = $this->perOrd((int)$r->anio,(int)$r->pciclo);
                $diff = abs($ord - $ordTarget);
                if ($diff < $bestDiff && $r->ciclo) { $best=$r; $bestDiff=$diff; }
            }
            if ($best) {
                $c0 = $this->toIntOrNull($best->ciclo) ?? 1;
                $delta = $this->perOrd($y,$c) - $this->perOrd((int)$best->anio,(int)$best->pciclo);
                $est = max(1, min(10, $c0 + $delta));
                return $est;
            }
        }
        return $this->toIntOrNull($exp->ciclo) ?? 1;
    }

    // ========================= Infraestructura proyecto/proceso/sesiones =========================
    // Un proyecto POR (periodo, nivel, sede). 1 proceso HORAS. 5 sesiones de 1h (08:00–13:00).

    private function ensureInfraProyectoNivel(int $epSedeId, string $periodoCodigo, int $nivel): array
    {
        [$anio,$ciclo] = $this->splitPeriodo($periodoCodigo);

        // Período
        $per = PeriodoAcademico::firstOrCreate(
            ['anio'=>$anio,'ciclo'=>$ciclo],
            [
                'codigo'       => sprintf('%04d-%d', $anio, $ciclo),
                'estado'       => $this->estadoPeriodoByFechas($anio,$ciclo),
                'es_actual'    => false,
                'fecha_inicio' => $this->defaultInicio($anio,$ciclo),
                'fecha_fin'    => $this->defaultFin($anio,$ciclo),
            ]
        );

        // Proyecto por (periodo, nivel)
        $nivel = max(1,min(10,$nivel));
        $code   = sprintf('HIST-%s-N%02d', $per->codigo, $nivel);
        $titulo = "VCM {$per->codigo} • Nivel {$nivel}";
        $desc   = "Proyecto histórico (sin evidencias previas). Nivel {$nivel}. "
                . "Creado para asentar horas de períodos anteriores al en curso. "
                . "Proceso único tipo HORAS. Sesiones de 1 hora (máx 5).";

        $proy = VmProyecto::firstOrCreate(
            ['ep_sede_id'=>$epSedeId, 'periodo_id'=>$per->id, 'codigo'=>$code],
            [
                'titulo'                     => $titulo,
                'descripcion'                => $desc,
                'tipo'                       => 'VINCULADO',
                'modalidad'                  => 'MIXTA',
                'estado'                     => 'PLANIFICADO',
                'horas_planificadas'         => 5, // piso/tope 5
                'horas_minimas_participante' => 5,
            ]
        );

        // Proceso único HORAS (horas_asignadas=5)
        $proc = $proy->procesos()->where('nombre','Carga histórica')->first();
        if (!$proc) {
            $proc = $proy->procesos()->create([
                'nombre'          => 'Carga histórica',
                'descripcion'     => 'Asiento histórico de horas (sin evidencias previas).',
                'tipo_registro'   => 'HORAS',
                'horas_asignadas' => 5,
                'orden'           => 1,
                'estado'          => 'PLANIFICADO',
            ]);
        } else {
            if ((int)$proc->horas_asignadas !== 5) {
                $proc->horas_asignadas = 5;
                $proc->save();
            }
        }

        // Sesiones: exactamente 5 slots de 1h el día fecha_fin (08:00..13:00)
        $fecha = Carbon::parse((string)$per->fecha_fin)->toDateString(); // normaliza a "YYYY-MM-DD"

        $desired = [];
        $baseDay = Carbon::parse($fecha)->startOfDay();
        for ($i=0; $i<5; $i++) {
            $hi = (clone $baseDay)->setTime(8 + $i, 0, 0);
            $hf = (clone $hi)->addHour();
            $desired[] = [$hi->format('H:i:s'), $hf->format('H:i:s')];
        }

        $existing = $proc->sesiones()
            ->whereDate('fecha', $fecha)
            ->get()
            ->map(fn($s)=>[$s->hora_inicio, $s->hora_fin, $s->id])
            ->all();

        $existingSet = [];
        $sesionIds   = [];
        foreach ($existing as $e) {
            $existingSet[$e[0].'|'.$e[1]] = $e[2];
        }
        foreach ($desired as [$hi,$hf]) {
            $k = $hi.'|'.$hf;
            if (!isset($existingSet[$k])) {
                $s = $proc->sesiones()->create([
                    'fecha'       => $fecha,
                    'hora_inicio' => $hi,
                    'hora_fin'    => $hf,
                    'estado'      => 'PLANIFICADO',
                ]);
                $sesionIds[] = $s->id;
            } else {
                $sesionIds[] = $existingSet[$k];
            }
        }

        return [
            'periodo_id'  => $per->id,
            'proyecto_id' => $proy->id,
            'proceso_id'  => $proc->id,
            'sesion_ids'  => $sesionIds,
        ];
    }

    /**
     * Infra de proyecto LIBRE histórico:
     * - código: HIST-LIBRE-{PER}-N{nivel}-P{pack}
     * - tipo: LIBRE
     * - 1 proceso HORAS, 5 sesiones de 1h en fecha_fin del período.
     */
    private function ensureInfraProyectoLibre(int $epSedeId, string $periodoCodigo, int $nivel, int $pack = 1): array
    {
        [$anio,$ciclo] = $this->splitPeriodo($periodoCodigo);

        // Período (igual que en ensureInfraProyectoNivel)
        $per = PeriodoAcademico::firstOrCreate(
            ['anio'=>$anio,'ciclo'=>$ciclo],
            [
                'codigo'       => sprintf('%04d-%d', $anio, $ciclo),
                'estado'       => $this->estadoPeriodoByFechas($anio,$ciclo),
                'es_actual'    => false,
                'fecha_inicio' => $this->defaultInicio($anio,$ciclo),
                'fecha_fin'    => $this->defaultFin($anio,$ciclo),
            ]
        );

        $nivel = max(1, min(10, $nivel));

        $code   = sprintf('HIST-LIBRE-%s-N%02d-P%02d', $per->codigo, $nivel, $pack);
        $titulo = "VCM LIBRE {$per->codigo} • Nivel {$nivel} • Pack {$pack}";
        $desc   = "Proyecto libre histórico (horas excedentes). Nivel {$nivel}, pack {$pack}. "
                . "Creado automáticamente para asentar horas que exceden el tope de 5h del proyecto vinculado. "
                . "Proceso único tipo HORAS. Sesiones de 1 hora (máx 5).";

        $proy = VmProyecto::firstOrCreate(
            ['ep_sede_id'=>$epSedeId, 'periodo_id'=>$per->id, 'codigo'=>$code],
            [
                'titulo'                     => $titulo,
                'descripcion'                => $desc,
                'tipo'                       => 'LIBRE',
                'modalidad'                  => 'MIXTA',
                'estado'                     => 'PLANIFICADO',
                'horas_planificadas'         => 5,
                'horas_minimas_participante' => 5,
            ]
        );

        // Proceso único HORAS para libres
        $proc = $proy->procesos()->where('nombre', 'Carga libre histórica')->first();
        if (!$proc) {
            $proc = $proy->procesos()->create([
                'nombre'          => 'Carga libre histórica',
                'descripcion'     => 'Asiento histórico de horas excedentes (sin evidencias previas).',
                'tipo_registro'   => 'HORAS',
                'horas_asignadas' => 5,
                'orden'           => 1,
                'estado'          => 'PLANIFICADO',
            ]);
        } else {
            if ((int)$proc->horas_asignadas !== 5) {
                $proc->horas_asignadas = 5;
                $proc->save();
            }
        }

        // Sesiones: 5 slots de 1h el día fecha_fin (08:00..13:00), igual que en proyecto VINCULADO
        $fecha = Carbon::parse((string)$per->fecha_fin)->toDateString();

        $desired = [];
        $baseDay = Carbon::parse($fecha)->startOfDay();
        for ($i=0; $i<5; $i++) {
            $hi = (clone $baseDay)->setTime(8 + $i, 0, 0);
            $hf = (clone $hi)->addHour();
            $desired[] = [$hi->format('H:i:s'), $hf->format('H:i:s')];
        }

        $existing = $proc->sesiones()
            ->whereDate('fecha', $fecha)
            ->get()
            ->map(fn($s)=>[$s->hora_inicio, $s->hora_fin, $s->id])
            ->all();

        $existingSet = [];
        $sesionIds   = [];
        foreach ($existing as $e) {
            $existingSet[$e[0].'|'.$e[1]] = $e[2];
        }
        foreach ($desired as [$hi,$hf]) {
            $k = $hi.'|'.$hf;
            if (!isset($existingSet[$k])) {
                $s = $proc->sesiones()->create([
                    'fecha'       => $fecha,
                    'hora_inicio' => $hi,
                    'hora_fin'    => $hf,
                    'estado'      => 'PLANIFICADO',
                ]);
                $sesionIds[] = $s->id;
            } else {
                $sesionIds[] = $existingSet[$k];
            }
        }

        return [
            'periodo_id'  => $per->id,
            'proyecto_id' => $proy->id,
            'proceso_id'  => $proc->id,
            'sesion_ids'  => $sesionIds,
        ];
    }

    private function defaultInicio(int $y, int $c): string
    {
        return $c===1 ? "{$y}-03-01" : "{$y}-08-01";
    }

    private function defaultFin(int $y, int $c): string
    {
        return $c===1 ? "{$y}-07-15" : "{$y}-12-15";
    }

    private function estadoPeriodoByFechas(int $y, int $c): string
    {
        $hoy = Carbon::today();
        $ini = Carbon::parse($this->defaultInicio($y,$c));
        $fin = Carbon::parse($this->defaultFin($y,$c));
        if ($hoy->lt($ini)) return 'PLANIFICADO';
        if ($hoy->gt($fin)) return 'CERRADO';
        return 'EN_CURSO';
    }

    // ========================= Reparto de horas "ANTES_DE" =========================

    /**
     * Reparte totalHoras en enteros entre los k períodos anteriores al anchor.
     * Reparto base con resto distribuido de forma estable (hash por alumno+anchor).
     * Devuelve map [periodoCodigo => horasAsignadas].
     */
    private function distribuirHorasEnterasAntesDe(string $codigoAlumno, string $anchorPer, int $totalHoras, int $k): array
    {
        if ($k <= 0 || $totalHoras <= 0) return [];
        $periods = $this->previousPeriods($anchorPer, $k); // antiguo..reciente
        $base = intdiv($totalHoras, $k);
        $rest = $totalHoras % $k;

        $assign = array_fill_keys($periods, $base);

        if ($rest > 0) {
            // selección estable pseudo-aleatoria: hash(codigo+anchor)
            $seed = crc32($codigoAlumno.'|'.$anchorPer);
            $idxs = range(0, $k-1);
            // Fisher-Yates con semilla simple
            for ($i=$k-1; $i>0; $i--) {
                $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
                $j = $seed % ($i+1);
                [$idxs[$i], $idxs[$j]] = [$idxs[$j], $idxs[$i]];
            }
            for ($r=0; $r<$rest; $r++) {
                $assign[$periods[$idxs[$r]]] += 1;
            }
        }

        return $assign; // cada valor ya está 0..N y luego cap(5) al aplicar
    }

    // ========================= Inscripción + asistencias + registros =========================

    /**
     * Inscribe al alumno en el proyecto (si no lo está) y registra H asistencias de 1h
     * (en las primeras H sesiones). Crea registro_horas por asistencia.
     * Retorna número de asistencias creadas/actualizadas.
     */
    private function inscribirYMarcarHoras(array $infra, ExpedienteAcademico $exp, string $codigo, string $periodoCodigo, int $nivel, int $horas, string $batch): int
    {
        $proyectoId = $infra['proyecto_id'];
        $procesoId  = $infra['proceso_id'];
        $sesionIds  = $infra['sesion_ids'];

        // Inscribir SOLO al proyecto que le corresponde en ese período
        VmParticipacion::firstOrCreate(
            [
                'participable_type' => VmProyecto::class,
                'participable_id'   => $proyectoId,
                'expediente_id'     => $exp->id,
            ],
            ['rol'=>'ALUMNO','estado'=>'INSCRITO']
        );

        $periodoId = PeriodoAcademico::where('codigo',$periodoCodigo)->value('id');
        $per = $periodoId ? PeriodoAcademico::find($periodoId) : null;
        $fechaRegistro = $per?->fecha_fin ?? now()->toDateString();

        $made  = 0;
        $horas = max(0, min(5, (int)$horas));
        if ($horas === 0) return 0;

        // Tomar las primeras H sesiones
        $use = array_slice($sesionIds, 0, $horas);

        foreach ($use as $sid) {
            $ses = VmSesion::find($sid);
            if (!$ses) continue;

            // Normaliza HH:MM → HH:MM:SS
            $hiStr = (string)$ses->hora_inicio;
            if (strlen($hiStr) === 5) $hiStr .= ':00';
            $hiStr = substr($hiStr, 0, 8);

            // Fecha solo día
            $fechaSolo = Carbon::parse((string)$ses->fecha)->toDateString();

            // check-in/out 1h
            $checkIn  = Carbon::parse($fechaSolo)->setTimeFromTimeString($hiStr);
            $checkOut = (clone $checkIn)->addHour();

            $a = VmAsistencia::updateOrCreate(
                ['sesion_id'=>$sid, 'expediente_id'=>$exp->id],
                [
                    'metodo'            => 'IMPORTADO',
                    'estado'            => 'VALIDADO',
                    'check_in_at'       => $checkIn,
                    'check_out_at'      => $checkOut,
                    'minutos_validados' => 60,
                    'meta'              => [
                        'source'  => 'horas_import',
                        'batch'   => $batch,
                        'codigo'  => $codigo,
                        'periodo' => $periodoCodigo,
                        'nivel'   => $nivel,
                    ],
                ]
            );

            // Registro de horas (1h = 60 min), único por asistencia
            DB::table('registro_horas')->updateOrInsert(
                [
                    'asistencia_id' => $a->id, // unique
                ],
                [
                    'expediente_id'   => $exp->id,
                    'ep_sede_id'      => $exp->ep_sede_id,
                    'periodo_id'      => $periodoId,
                    'fecha'           => $fechaRegistro,
                    'minutos'         => 60,
                    'actividad'       => "Carga histórica {$periodoCodigo} (nivel {$nivel})",
                    'estado'          => 'APROBADO',
                    'vinculable_type' => VmProceso::class,
                    'vinculable_id'   => $procesoId,
                    'sesion_id'       => $sid,
                    'updated_at'      => now(),
                    'created_at'      => now(),
                ]
            );

            $made++;
        }

        return $made;
    }

    /**
     * Reparte horas libres (sobrantes del tope 5h del proyecto vinculado)
     * en proyectos HIST-LIBRE-{PER}-N{nivel}-P{pack}, solo si el período está CERRADO.
     * Cada proyecto libre también es de máx 5h (5 sesiones).
     *
     * Devuelve cantidad de asistencias creadas/actualizadas.
     */
    private function inscribirHorasEnProyectosLibres(
        int $epSedeId,
        array &$infraLibreCache,
        ExpedienteAcademico $exp,
        string $codigo,
        string $periodoCodigo,
        int $nivel,
        int $horasLibres,
        string $batch
    ): int {
        $horasLibres = (int)$horasLibres;
        if ($horasLibres <= 0) {
            return 0;
        }

        // Solo colocar horas libres en períodos CERRADOS
        $estado = $this->estadoPeriodoByCodigo($periodoCodigo);
        if ($estado !== 'CERRADO') {
            // Si quieres permitir también PLANIFICADO, cambia la condición de arriba.
            return 0;
        }

        $made    = 0;
        $keyBase = $periodoCodigo.'|'.$nivel;
        $pack    = 1;

        while ($horasLibres > 0) {
            $take = min(5, $horasLibres);

            $cacheKey = $keyBase.'|'.$pack;
            if (!isset($infraLibreCache[$cacheKey])) {
                $infraLibreCache[$cacheKey] = $this->ensureInfraProyectoLibre($epSedeId, $periodoCodigo, $nivel, $pack);
            }

            $made += $this->inscribirYMarcarHoras(
                $infraLibreCache[$cacheKey],
                $exp,
                $codigo,
                $periodoCodigo,
                $nivel,
                $take,
                $batch
            );

            $horasLibres -= $take;
            $pack++;
        }

        return $made;
    }

    // ========================= Resolución de expediente y códigos =========================

    /**
     * Busca expediente por código “inteligente”.
     * Acepta variantes: con/sin ceros a la izquierda y limpieza básica.
     * Devuelve [ExpedienteAcademico|null, metaNotFound|[]]
     */
    private function resolveExpedienteSmart(int $epSedeId, string $raw): array
    {
        $q = trim($raw);
        if ($q === '') return [null, []];

        $cand = [];
        $cand[] = $q;
        $cand[] = ltrim($q, '0');                 // sin ceros
        $cand[] = preg_replace('/\s+/', '', $q);   // sin espacios

        $cand = array_values(array_unique(array_filter($cand, fn($s) => $s !== '')));

        foreach ($cand as $code) {
            $exp = ExpedienteAcademico::where('ep_sede_id',$epSedeId)
                ->where('codigo_estudiante',$code)->first();
            if ($exp) return [$exp, []];
        }

        return [null, ['tried_variants'=>$cand]];
    }

    private function estadoPeriodoByCodigo(string $codigo): ?string
    {
        $p = PeriodoAcademico::where('codigo',$codigo)->first();
        return $p?->estado;
    }

    /**
     * 📄 Descargar plantilla Excel para carga histórica.
     * GET /api/vm/import/historico-horas/plantilla
     * Query:
     *  - periodos[] = ['2024-1','2024-2', ...]  (opcional)
     *  - ultimos    = N (si no mandas periodos[], por defecto 6)
     *
     * Columna CODIGO + columnas por período + 1 columna "ANTES_DE_{anchor}".
     * El anchor se calcula como el período siguiente al más reciente de la lista,
     * para que "ANTES_DE" reparta horas hacia atrás de forma natural.
     */
    public function template(Request $request)
    {
        $actor = $this->requireAuth($request);

        // Periodos seleccionados o últimos N (excluyendo en curso si existe)
        $periodosReq = $request->input('periodos', []);
        $ultimos     = max(1, min(12, (int)$request->input('ultimos', 6)));

        $perActual   = $this->getPeriodoActual();
        $perActualCod = $perActual?->codigo;

        $periodos = [];
        if (is_array($periodosReq) && count($periodosReq) > 0) {
            $periodos = array_values(array_unique(array_map(function($v){
                $v = (string)$v; $v = str_replace('_','-',$v);
                return strtoupper($v);
            }, $periodosReq)));
        } else {
            // Tomar los últimos N períodos desde la tabla (excluye EN_CURSO)
            $rows = PeriodoAcademico::query()
                ->when($perActualCod, fn($q) => $q->where('codigo','!=',$perActualCod))
                ->orderByDesc('anio')->orderByDesc('ciclo')
                ->limit($ultimos)
                ->get();

            if ($rows->count() === 0) {
                // Fallback simple si no hay periodos en BD
                $y = (int)date('Y');
                $c = 2;
                for ($i=0; $i<$ultimos; $i++) {
                    $periodos[] = sprintf('%04d-%d', $y, $c);
                    // retrocede un período
                    if ($c===2) { $c=1; } else { $c=2; $y--; }
                }
            } else {
                foreach ($rows as $p) $periodos[] = sprintf('%04d-%d', $p->anio, $p->ciclo);
            }
            // Orden del más antiguo al más reciente en la plantilla
            $periodos = array_reverse($periodos);
        }

        if (empty($periodos)) {
            return response()->json(['ok'=>false,'message'=>'No hay períodos para armar la plantilla.'], 422);
        }

        // Anchor para la columna ANTES_DE_{anchor} = período siguiente al MÁS RECIENTE
        $maxPer   = end($periodos); reset($periodos);
        $anchor   = $this->perNext($maxPer); // p.ej. si max es 2024-2 => anchor 2025-1
        $antesCol = 'ANTES_DE_'.str_replace('_','-',$anchor);

        // Construcción del XLSX
        $xl = new Spreadsheet();
        $sheet = $xl->getActiveSheet();
        $sheet->setTitle('Plantilla horas');

        // Encabezados
        $headers = array_merge(['CODIGO'], $periodos, [$antesCol]);
        $col = 1;
        foreach ($headers as $h) {
            $sheet->setCellValueByColumnAndRow($col, 1, $h);
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            $col++;
        }

        // Fila de ejemplo (opcional)
        // CODIGO + valores de horas (en HORAS, no minutos)
        $example = array_fill_keys($headers, '');
        $example['CODIGO'] = '00012345';
        // ejemplo: 2h en el período más antiguo y 1h en el siguiente
        if (isset($periodos[0])) $example[$periodos[0]] = 2;
        if (isset($periodos[1])) $example[$periodos[1]] = 1;
        // y 3h antes del anchor (se repartirán hacia atrás por el import)
        $example[$antesCol] = 3;

        $col = 1;
        foreach ($headers as $h) {
            $sheet->setCellValueByColumnAndRow($col, 2, $example[$h]);
            $col++;
        }

        // Notas (fila 4, en una celda)
        $sheet->setCellValue('A4',
            "Notas:\n".
            "• Completa la columna CODIGO con el código del estudiante.\n".
            "• Las columnas 'YYYY-1/2' aceptan HORAS (se convierten a 60 min por hora, máx 5 h por período/proyecto).\n".
            "• La columna '{$antesCol}' reparte las horas entre los ".$this->countPrevious($anchor, count($periodos))." períodos anteriores a {$anchor}.\n".
            "• Nunca se crean registros en el período en curso.\n".
            "• Formatos aceptados de cabeceras: 'YYYY-1', 'YYYY-2' y 'ANTES_DE_YYYY' o 'ANTES_DE_YYYY-1/2'."
        );
        $sheet->getStyle('A4')->getAlignment()->setWrapText(true);

        // Estilos mínimos
        $sheet->getStyle('A1:'.$sheet->getCellByColumnAndRow(count($headers),1)->getCoordinate())
            ->getFont()->setBold(true);

        $filename = 'plantilla_horas_historicas_'.$anchor.'.xlsx';
        $headersOut = ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

        return response()->streamDownload(function() use ($xl) {
            IOFactory::createWriter($xl, 'Xlsx')->save('php://output');
        }, $filename, $headersOut);
    }

    /**
     * 🔎 Estado simple: ¿existen horas registradas?
     * GET /api/vm/import/historico-horas/status
     * Query:
     *  - ep_sede_id  (opcional; si el usuario administra 1, se resuelve solo)
     *  - periodos[]  (opcional; si se envían, filtra por esos códigos)
     */
    public function status(Request $request)
    {
        $actor = $this->requireAuth($request);
        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        $periodos = $request->input('periodos', []);
        $q = DB::table('registro_horas')->where('ep_sede_id', $epSedeId);

        if (is_array($periodos) && count($periodos) > 0) {
            $codes = array_values(array_unique(array_map(fn($v)=>strtoupper(str_replace('_','-',(string)$v)), $periodos)));
            $perIds = PeriodoAcademico::whereIn('codigo', $codes)->pluck('id')->all();
            if (!empty($perIds)) $q->whereIn('periodo_id', $perIds);
        }

        $has = $q->limit(1)->exists();

        return response()->json([
            'ok' => true,
            'ep_sede_id' => $epSedeId,
            'has_horas'  => (bool)$has,
        ]);
    }

    /** helper para nota de plantilla */
    private function countPrevious(string $anchor, int $k): int
    {
        // Número de períodos previos que cubriría ANTES_DE (k)
        return max(0, $k);
    }

}
