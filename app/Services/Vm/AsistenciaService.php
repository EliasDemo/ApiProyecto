<?php

namespace App\Services\Vm;

use App\Models\User;
use App\Models\VmSesion;
use App\Models\VmProceso;
use App\Models\VmEvento;
use App\Models\VmProyecto;
use App\Models\VmQrToken;
use App\Models\VmAsistencia;
use App\Models\VmParticipacion;
use App\Models\RegistroHora;
use App\Models\ExpedienteAcademico;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Carbon\Carbon;

/**
 * Reglas de permisos:
 * - Solo se exige permiso cuando la acción es de GESTIÓN sobre la EP-Sede vinculada a la sesión.
 * - Se reutiliza SIEMPRE el MISMO permiso: 'ep.manage.ep_sede'.
 * - Consultas auxiliares / utilidades NO requieren permiso.
 */
class AsistenciaService
{
    public const WINDOW_MINUTES = 30;

    // 🔐 Permiso único reutilizado para todas las gestiones
    public const PERM_MANAGE_EP_SEDE = 'ep.manage.ep_sede';

    // =========================================================
    // Helpers de hora/fecha (evitan Double time specification)
    // =========================================================

    /** Normaliza "H:i" a "H:i:s". Devuelve null si no es válida. */
    private function normalizeHora(?string $t): ?string
    {
        if (!$t) return null;
        if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t . ':00';
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;
        try {
            return Carbon::parse($t)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Crea un Carbon a partir de una fecha (date|datetime|string|Carbon)
     * y una hora HH:MM[:SS], sin concatenar strings.
     */
    public function at(string|\DateTimeInterface $fecha, string $hhmmss): Carbon
    {
        $base = $fecha instanceof Carbon ? $fecha->copy() : Carbon::parse($fecha);
        $h    = $this->normalizeHora($hhmmss) ?? '00:00:00';
        return $base->copy()->setTimeFromTimeString($h);
    }

    /**
     * Límites REALES de la sesión [inicio, fin] (sin buffers).
     * - Si cruza medianoche, ajusta fin +1 día.
     * - Si falta alguna hora, da un rango razonable.
     * @return array{0:Carbon,1:Carbon}
     */
    public function boundsForSesion(VmSesion $sesion): array
    {
        $base = $sesion->fecha instanceof Carbon
            ? $sesion->fecha->copy()
            : Carbon::parse($sesion->fecha ?: now()->toDateString());

        $hi = $this->normalizeHora($sesion->hora_inicio);
        $hf = $this->normalizeHora($sesion->hora_fin);

        if ($hi && $hf) {
            $start = $base->copy()->setTimeFromTimeString($hi);
            $end   = $base->copy()->setTimeFromTimeString($hf);
            if ($end->lessThan($start)) $end->addDay();
            return [$start, $end];
        }

        if ($hi) {
            $start = $base->copy()->setTimeFromTimeString($hi);
            $end   = $start->copy()->addHour(); // fallback: 1h
            return [$start, $end];
        }

        if ($hf) {
            $end   = $base->copy()->setTimeFromTimeString($hf);
            $start = $end->copy()->subHour(); // fallback: 1h antes
            return [$start, $end];
        }

        // Sin horas: todo el día
        return [$base->copy()->startOfDay(), $base->copy()->endOfDay()];
    }

    /**
     * Ventana de check-in: [inicio-1h, fin+1h] (si hay horas); si no, todo el día.
     * @return array{0:Carbon,1:Carbon}
     */
    public function timeWindowForSesion(VmSesion $sesion): array
    {
        [$start, $end] = $this->boundsForSesion($sesion);
        return [$start->copy()->subHour(), $end->copy()->addHour()];
    }

    /** Minutos reales de la sesión (corrigiendo cruce de medianoche). */
    public function minutosSesion(VmSesion $sesion): int
    {
        [$start, $end] = $this->boundsForSesion($sesion);
        return $start->diffInMinutes($end);
    }

    // =========================
    // TOKENS (REQUIEREN PERMISO)
    // =========================

    /**
     * Generar token (QR o MANUAL) para una sesión.
     * 🔐 Requiere: ep.manage.ep_sede sobre la EP-Sede de la sesión.
     */
    public function generarToken(
        User $actor,
        VmSesion $sesion,
        string $tipo = 'QR',
        ?array $geo = null,
        ?int $maxUsos = null,
        ?int $creadoPor = null
    ): VmQrToken {
        $epSedeId = $this->epSedeIdDesdeSesion($sesion);
        $this->assertCanManageEpSede($actor, $epSedeId);

        $now = now();

        return DB::transaction(function () use ($sesion, $tipo, $geo, $maxUsos, $creadoPor, $now, $actor) {
            $t = new VmQrToken();
            $t->forceFill([
                'sesion_id'   => $sesion->id,
                'token'       => bin2hex(random_bytes(16)), // 32 chars hex
                'tipo'        => $tipo,                     // QR | MANUAL
                'usable_from' => $now,
                'expires_at'  => $now->copy()->addMinutes(self::WINDOW_MINUTES),
                'max_usos'    => $maxUsos,
                'usos'        => 0,
                'activo'      => true,
                'creado_por'  => $creadoPor ?? $actor->id,
                'lat'         => $geo['lat']     ?? null,
                'lng'         => $geo['lng']     ?? null,
                'radio_m'     => $geo['radio_m'] ?? null,
                'meta'        => null,
            ])->save();

            return $t->refresh();
        });
    }

    /**
     * Token MANUAL alineado a la sesión (frontera real, sin buffers).
     * 🔐 Requiere: ep.manage.ep_sede.
     */
    public function generarTokenManualAlineado(
        User $actor,
        VmSesion $sesion,
        ?int $creadoPor = null
    ): VmQrToken {
        $epSedeId = $this->epSedeIdDesdeSesion($sesion);
        $this->assertCanManageEpSede($actor, $epSedeId);

        [$start, $end] = $this->boundsForSesion($sesion);

        return DB::transaction(function () use ($sesion, $creadoPor, $start, $end, $actor) {
            $t = new VmQrToken();
            $t->forceFill([
                'sesion_id'   => $sesion->id,
                'token'       => bin2hex(random_bytes(16)),
                'tipo'        => 'MANUAL',
                'usable_from' => $start,
                'expires_at'  => $end,
                'max_usos'    => null,
                'usos'        => 0,
                'activo'      => true,
                'creado_por'  => $creadoPor ?? $actor->id,
            ])->save();
            return $t->refresh();
        });
    }

    // =========================
    // VALIDACIONES TOKEN/UBICACIÓN (NO requieren permiso)
    // =========================

    public function checkVentana(VmQrToken $t): void
    {
        $now = now();
        if (
            !$t->activo ||
            ($t->usable_from && $now->lt($t->usable_from)) ||
            ($t->expires_at && $now->gt($t->expires_at))
        ) {
            throw ValidationException::withMessages(['token' => 'VENTANA_INVALIDA']);
        }
        if (!is_null($t->max_usos) && $t->usos >= $t->max_usos) {
            throw ValidationException::withMessages(['token' => 'VENTANA_SIN_CUPO']);
        }
    }

    public function checkGeofence(?VmQrToken $t, ?float $lat, ?float $lng): void
    {
        if (!$t || !$t->lat || !$t->lng || !$t->radio_m) return;
        if ($lat === null || $lng === null) {
            throw ValidationException::withMessages(['geo' => 'GEO_REQUERIDA']);
        }
        $dist = $this->haversine((float)$t->lat, (float)$t->lng, (float)$lat, (float)$lng);
        if ($dist > (int)$t->radio_m) {
            throw ValidationException::withMessages(['geo' => 'FUERA_DE_RANGO']);
        }
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $R = 6371000; // m
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)**2;
        return (int) round(2 * $R * atan2(sqrt($a), sqrt(1-$a)));
    }

    // =========================
    // RESOLUCIONES ESTRUCTURALES (NO requieren permiso)
    // =========================

    /**
     * [ptype, pid, ep_sede_id, periodo_id] detectados a partir de la sesión.
     */
    protected function datosDesdeSesion(VmSesion $sesion): array
    {
        $owner = $sesion->sessionable; // VmProceso | VmEvento | VmProyecto | null

        // ───── Sesión de PROCESO (Proyecto) ─────
        if ($owner instanceof VmProceso) {
            $owner->loadMissing('proyecto');
            $p = $owner->proyecto;
            if ($p) {
                return [
                    VmProyecto::class,
                    (int) $p->id,
                    (int) $p->ep_sede_id,
                    (int) $p->periodo_id,
                ];
            }
        }

        // ───── Sesión directamente sobre PROYECTO (por si acaso) ─────
        if ($owner instanceof VmProyecto) {
            return [
                VmProyecto::class,
                (int) $owner->id,
                (int) $owner->ep_sede_id,
                (int) $owner->periodo_id,
            ];
        }

        // ───── Sesión de EVENTO ─────
        if ($owner instanceof VmEvento) {
            return [
                VmEvento::class,
                (int) $owner->id,
                $owner->ep_sede_id ? (int) $owner->ep_sede_id : null,
                (int) $owner->periodo_id,
            ];
        }

        // Fallback por si no está cargada la relación morph
        $type = strtolower((string) $sesion->sessionable_type);
        $sid  = (int) $sesion->sessionable_id;

        if (str_contains($type, 'proceso')) {
            $row = DB::table('vm_procesos as pr')
                ->join('vm_proyectos as p', 'p.id', '=', 'pr.proyecto_id')
                ->select('p.id as pid', 'p.ep_sede_id', 'p.periodo_id')
                ->where('pr.id', $sid)
                ->first();

            if ($row) {
                return [
                    VmProyecto::class,
                    (int) $row->pid,
                    (int) $row->ep_sede_id,
                    (int) $row->periodo_id,
                ];
            }
        }

        if (str_contains($type, 'evento')) {
            // Aquí YA NO leemos ep_sede_id de la tabla (no existe).
            // Usamos el modelo + accessor ep_sede_id.
            $ev = VmEvento::find($sid);
            if ($ev) {
                return [
                    VmEvento::class,
                    (int) $ev->id,
                    $ev->ep_sede_id ? (int) $ev->ep_sede_id : null,
                    (int) $ev->periodo_id,
                ];
            }
        }

        return [null, null, null, null];
    }

    /** Sólo el ep_sede_id (o null si no se puede resolver). */
    public function epSedeIdDesdeSesion(VmSesion $sesion): ?int
    {
        [, , $ep] = $this->datosDesdeSesion($sesion);
        return $ep;
    }

    /** sessionable → participable (Proyecto para Proceso; Evento para Evento). */
    public function participableDesdeSesion(VmSesion $sesion): array
    {
        [$ptype, $pid] = $this->datosDesdeSesion($sesion);
        if ($ptype === VmProyecto::class) return [VmProyecto::class, (int) $pid];
        if ($ptype === VmEvento::class)   return [VmEvento::class,   (int) $pid];
        return [null, null];
    }

    // =========================
    // EXPEDIENTES (permiso solo para terceros)
    // =========================

    public function resolverExpedientePorUser(User $actor, User $user, ?int $epSedeId): ?ExpedienteAcademico
    {
        if (!$epSedeId) return null;

        if ($actor->id !== $user->id) {
            $this->assertCanManageEpSede($actor, $epSedeId);
        }

        return ExpedienteAcademico::where('user_id', $user->id)
            ->where('ep_sede_id', $epSedeId)
            ->first();
    }

    public function resolverExpedientePorIdentificador(User $actor, string $dniOCodigo, ?int $epSedeId): ?ExpedienteAcademico
    {
        if (!$epSedeId) return null;

        $this->assertCanManageEpSede($actor, $epSedeId);

        return ExpedienteAcademico::query()
            ->where('ep_sede_id', $epSedeId)
            ->where(function ($q) use ($dniOCodigo) {
                $q->where('codigo_estudiante', $dniOCodigo)
                  ->orWhereHas('user', fn($qq) => $qq->where('doc_numero', $dniOCodigo));
            })
            ->first();
    }

    // =========================
    // ASISTENCIAS
    // =========================

    /**
     * Crear/actualizar asistencia.
     * - Con token QR válido: NO requiere permiso (flujo autoatendido).
     * - Manual (sin token): 🔐 requiere ep.manage.ep_sede.
     */
    public function upsertAsistencia(
        User $actor,
        VmSesion $sesion,
        ExpedienteAcademico $exp,
        string $metodo,
        ?VmQrToken $token = null,
        ?array $meta = null
    ): VmAsistencia {
        $isManual = strtoupper($metodo) === 'MANUAL' || !$token;
        if ($isManual) {
            $epSedeId = $this->epSedeIdDesdeSesion($sesion);
            $this->assertCanManageEpSede($actor, $epSedeId);
        }

        return DB::transaction(function () use ($sesion, $exp, $metodo, $token, $meta) {
            /** @var VmAsistencia $a */
            $a = VmAsistencia::firstOrNew([
                'sesion_id'     => $sesion->id,
                'expediente_id' => $exp->id,
            ]);

            if (!$a->exists || !$a->check_in_at) {
                $a->check_in_at = now();
            }

            // Participación a nivel Proyecto/Evento
            [$ptype, $pid] = $this->participableDesdeSesion($sesion);
            if ($ptype && $pid) {
                $a->participacion_id = VmParticipacion::where([
                    'participable_type' => $ptype,
                    'participable_id'   => $pid,
                    'expediente_id'     => $exp->id,
                ])->value('id');
            }

            $a->metodo            = $metodo; // 'QR' | 'MANUAL' | ...
            $a->qr_token_id       = $token?->id;
            $a->estado            = $a->estado ?: 'PENDIENTE';
            $a->minutos_validados = $a->minutos_validados ?? 0;

            $prev = is_array($a->meta) ? $a->meta : [];
            $a->meta = array_merge($prev, $meta ?? []);
            $a->save();

            // Incrementar usos del token
            if ($token) {
                $token->usos = (int) $token->usos + 1;
                $token->save();
            }

            return $a->refresh();
        });
    }

    /**
     * Validar asistencia (y crear/actualizar RegistroHora).
     * 🔐 Requiere ep.manage.ep_sede.
     */
    public function validarAsistencia(User $actor, VmAsistencia $a, int $minutos, bool $crearRegistroHoras = true): VmAsistencia
    {
        $sesion = $a->sesion;
        $epSedeId = $this->epSedeIdDesdeSesion($sesion);
        $this->assertCanManageEpSede($actor, $epSedeId);

        $a->estado = 'VALIDADO';
        $a->minutos_validados = $minutos;
        $a->check_out_at = $a->check_out_at ?? now();
        $a->save();

        if ($crearRegistroHoras && $minutos > 0) {
            $this->crearOActualizarRegistroHora($a, $minutos);
        }
        return $a;
    }

    // =========================
    // Registro de horas
    // =========================
    protected function crearOActualizarRegistroHora(VmAsistencia $a, int $minutos): void
    {
        $sesion = $a->sesion;
        [$ptype, $pid, $epSedeId, $periodoId] = $this->datosDesdeSesion($sesion);
        if (!$ptype || !$pid || !$epSedeId || !$periodoId) return;

        // Fecha = día de INICIO de la sesión
        [$start] = $this->boundsForSesion($sesion);
        $fecha = $start->toDateString();

        $reg = RegistroHora::firstOrNew(['asistencia_id' => $a->id]);

        $reg->fill([
            'expediente_id'  => $a->expediente_id,
            'ep_sede_id'     => $epSedeId,
            'periodo_id'     => $periodoId,
            'fecha'          => $fecha,
            'minutos'        => $minutos,
            'actividad'      => 'Asistencia sesión '.$sesion->id,
            'estado'         => 'APROBADO',
            'vinculable_type'=> $ptype,
            'vinculable_id'  => $pid,
            'sesion_id'      => $sesion->id,
        ]);

        if ($reg->exists) {
            $reg->minutos = $minutos;
            $reg->estado  = 'APROBADO';
        }
        $reg->save();
    }

    // =========================
    // Helpers de autorización
    // =========================

    /** Lanza AuthorizationException si el actor no puede gestionar la EP-Sede. */
    protected function assertCanManageEpSede(User $actor, ?int $epSedeId): void
    {
        if (!$epSedeId) {
            throw ValidationException::withMessages(['sesion' => 'SESION_SIN_EP_SEDE']);
        }
        if (!$actor->can(self::PERM_MANAGE_EP_SEDE)) {
            throw new AuthorizationException('NO_AUTORIZADO_EP_SEDE');
        }
    }
}
