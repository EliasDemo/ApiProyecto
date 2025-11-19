<?php

namespace App\Services\Vm;

use App\Models\{VmProceso, VmProyecto, VmEvento, VmSesion};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EstadoService
{
    /** Punto de entrada */
    public function recalcOwner(?Model $owner): void
    {
        if (!$owner) {
            Log::warning('[EstadoService] recalcOwner sin owner');
            return;
        }

        try {
            if ($owner instanceof VmProceso) {
                $this->recalcProceso($owner);
                if ($owner->proyecto) {
                    $this->recalcProyecto($owner->proyecto);
                }
            } elseif ($owner instanceof VmProyecto) {
                $this->recalcProyecto($owner);
            } elseif ($owner instanceof VmEvento) {
                $this->recalcEvento($owner);
            } else {
                Log::warning('[EstadoService] Owner no soportado', [
                    'owner_type' => get_class($owner),
                    'owner_id'   => $owner->id ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[EstadoService] recalcOwner falló', [
                'owner_type' => get_class($owner),
                'owner_id'   => $owner->id ?? null,
                'msg'        => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** PROCESO */
    protected function recalcProceso(VmProceso $proceso): void
    {
        if ($proceso->estado === 'CANCELADO') return;
        $now = now();

        // (Opcional) autocorrección de sesiones si se habilita por config
        if (config('vm.auto_normalize_sessions', true)) {
            try {
                [$st,$cl] = $this->normalizeSesionesOwner($proceso, $now);
                if ($st || $cl) {
                    Log::info('[EstadoService] Normalize sesiones PROCESO', [
                        'proceso_id' => $proceso->id, 'started' => $st, 'closed' => $cl
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[EstadoService] normalizeSesionesOwner proceso falló', [
                    'proceso_id' => $proceso->id, 'msg' => $e->getMessage(),
                ]);
            }
        }

        try {
            [$total, $mi, $mf, $hasPast, $hasFuture, $hasRun] =
                $this->computeWindowAndFlags($proceso->sesiones(), $now);

            $new = $this->stateFromWindow($total, $mi, $mf, $now);

            // Correcciones de consistencia
            $fixReason = null;
            if ($new === 'PLANIFICADO' && $total > 0) {
                if ($hasPast && $hasFuture) { $new = 'EN_CURSO'; $fixReason = 'fix: pasadas y futuras'; }
                elseif ($hasPast && !$hasFuture) { $new = 'CERRADO'; $fixReason = 'fix: solo pasadas'; }
            }
            if ($new !== 'EN_CURSO' && $hasRun) { $new = 'EN_CURSO'; $fixReason = $fixReason ?: 'fix: hay EN_CURSO'; }

            if ($new !== $proceso->estado) {
                $old = $proceso->estado;
                $proceso->update(['estado' => $new]);

                Log::info('[EstadoService] Proceso actualizado', [
                    'proceso_id' => $proceso->id, 'from' => $old, 'to' => $new,
                    'mi' => $mi?->toDateTimeString(), 'mf' => $mf?->toDateTimeString(),
                    'hasPast' => $hasPast, 'hasFuture' => $hasFuture, 'fix' => $fixReason,
                    'now' => $now->toDateTimeString(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[EstadoService] recalcProceso error', [
                'proceso_id' => $proceso->id, 'msg' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** PROYECTO */
    protected function recalcProyecto(VmProyecto $proyecto): void
    {
        if ($proyecto->estado === 'CANCELADO') return;
        $now = now();

        try {
            $sessionsQ = VmSesion::query()
                ->whereHasMorph('sessionable', [VmProceso::class], function ($q) use ($proyecto) {
                    $q->where('proyecto_id', $proyecto->id);
                });

            [$total, $mi, $mf, $hasPast, $hasFuture, $hasRun] =
                $this->computeWindowAndFlags($sessionsQ, $now);

            $new = $this->stateFromWindow($total, $mi, $mf, $now);

            $fixReason = null;
            if ($new === 'PLANIFICADO' && $total > 0) {
                if ($hasPast && $hasFuture) { $new = 'EN_CURSO'; $fixReason = 'fix: pasadas y futuras'; }
                elseif ($hasPast && !$hasFuture) { $new = 'CERRADO'; $fixReason = 'fix: solo pasadas'; }
            }
            if ($new !== 'EN_CURSO' && $hasRun) { $new = 'EN_CURSO'; $fixReason = $fixReason ?: 'fix: hay EN_CURSO'; }

            if ($new !== $proyecto->estado) {
                $old = $proyecto->estado;
                $proyecto->update(['estado' => $new]);

                Log::info('[EstadoService] Proyecto actualizado', [
                    'proyecto_id' => $proyecto->id, 'from' => $old, 'to' => $new,
                    'mi' => $mi?->toDateTimeString(), 'mf' => $mf?->toDateTimeString(),
                    'hasPast' => $hasPast, 'hasFuture' => $hasFuture, 'fix' => $fixReason,
                    'now' => $now->toDateTimeString(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[EstadoService] recalcProyecto error', [
                'proyecto_id' => $proyecto->id, 'msg' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** EVENTO */
    protected function recalcEvento(VmEvento $evento): void
    {
        if ($evento->estado === 'CANCELADO') return;
        $now = now();

        if (config('vm.auto_normalize_sessions', true)) {
            try {
                [$st,$cl] = $this->normalizeSesionesOwner($evento, $now);
                if ($st || $cl) {
                    Log::info('[EstadoService] Normalize sesiones EVENTO', [
                        'evento_id' => $evento->id, 'started' => $st, 'closed' => $cl
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[EstadoService] normalizeSesionesOwner evento falló', [
                    'evento_id' => $evento->id, 'msg' => $e->getMessage(),
                ]);
            }
        }

        try {
            [$total, $mi, $mf, $hasPast, $hasFuture, $hasRun] =
                $this->computeWindowAndFlags($evento->sesiones(), $now);

            $new = $this->stateFromWindow($total, $mi, $mf, $now);

            $fixReason = null;
            if ($new === 'PLANIFICADO' && $total > 0) {
                if ($hasPast && $hasFuture) { $new = 'EN_CURSO'; $fixReason = 'fix: pasadas y futuras'; }
                elseif ($hasPast && !$hasFuture) { $new = 'CERRADO'; $fixReason = 'fix: solo pasadas'; }
            }
            if ($new !== 'EN_CURSO' && $hasRun) { $new = 'EN_CURSO'; $fixReason = $fixReason ?: 'fix: hay EN_CURSO'; }

            if ($new !== $evento->estado) {
                $old = $evento->estado;
                $evento->update(['estado' => $new]);

                Log::info('[EstadoService] Evento actualizado', [
                    'evento_id' => $evento->id, 'from' => $old, 'to' => $new,
                    'mi' => $mi?->toDateTimeString(), 'mf' => $mf?->toDateTimeString(),
                    'hasPast' => $hasPast, 'hasFuture' => $hasFuture, 'fix' => $fixReason,
                    'now' => $now->toDateTimeString(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[EstadoService] recalcEvento error', [
                'evento_id' => $evento->id, 'msg' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** Regla base por ventana temporal */
    private function stateFromWindow(int $total, ?Carbon $mi, ?Carbon $mf, Carbon $now): string
    {
        if ($total === 0 || !$mi || !$mf) return 'PLANIFICADO';
        if ($now->lt($mi))                return 'PLANIFICADO';
        if ($now->gte($mf))               return 'CERRADO';
        return 'EN_CURSO';
    }

    /**
     * Ventana y banderas.
     * @param  EloquentBuilder|Relation $sessionsQ
     * @return array{int, ?Carbon, ?Carbon, bool, bool, bool}
     */
    private function computeWindowAndFlags(EloquentBuilder|Relation $sessionsQ, Carbon $now): array
    {
        // NOTA: usamos SQL con DATE (fecha) + TIME (hora_*), sin concatenaciones en PHP
        $qb = $sessionsQ instanceof Relation ? $sessionsQ->getQuery() : $sessionsQ;
        $nowStr = $now->format('Y-m-d H:i:s');

        $agg = (clone $qb)
            ->selectRaw("
                COUNT(*) AS total,
                MIN(TIMESTAMP(CONCAT(CAST(fecha AS CHAR), ' ', CAST(hora_inicio AS CHAR)))) AS mi,
                MAX(TIMESTAMP(CONCAT(CAST(fecha AS CHAR), ' ', CAST(hora_fin AS CHAR))))    AS mf
            ")
            ->first();

        $total = (int) ($agg->total ?? 0);
        $mi    = $agg->mi ? Carbon::parse($agg->mi) : null;
        $mf    = $agg->mf ? Carbon::parse($agg->mf) : null;

        $hasPast = (clone $qb)
            ->whereRaw("TIMESTAMP(CONCAT(CAST(fecha AS CHAR), ' ', CAST(hora_fin AS CHAR))) <= ?", [$nowStr])
            ->exists();

        $hasFuture = (clone $qb)
            ->whereRaw("TIMESTAMP(CONCAT(CAST(fecha AS CHAR), ' ', CAST(hora_inicio AS CHAR))) >  ?", [$nowStr])
            ->exists();

        $hasRun = (clone $qb)
            ->where('estado', 'EN_CURSO')
            ->exists();

        return [$total, $mi, $mf, $hasPast, $hasFuture, $hasRun];
    }

    /** Auto-heal de sesiones del owner (opcional) */
    protected function normalizeSesionesOwner(VmProceso|VmEvento $owner, Carbon $now): array
    {
        $nowStr = $now->format('Y-m-d H:i:s');

        $qb = $owner->sesiones() instanceof MorphMany
            ? $owner->sesiones()->getQuery()
            : $owner->sesiones();

        // 1) PLANIFICADO → EN_CURSO
        $started = (clone $qb)
            ->where('estado', 'PLANIFICADO')
            ->whereRaw("TIMESTAMP(CONCAT(CAST(fecha AS CHAR), ' ', CAST(hora_inicio AS CHAR))) <= ?", [$nowStr])
            ->whereRaw("TIMESTAMP(CONCAT(CAST(fecha AS CHAR), ' ', CAST(hora_fin AS CHAR))) >  ?", [$nowStr])
            ->update(['estado' => 'EN_CURSO']);

        // 2) PLANIFICADO|EN_CURSO → CERRADO (evita cierre si fin==inicio en el mismo tick)
        $closed = (clone $qb)
            ->whereIn('estado', ['PLANIFICADO', 'EN_CURSO'])
            ->whereRaw("TIMESTAMP(CONCAT(CAST(fecha AS CHAR), ' ', CAST(hora_fin AS CHAR))) <= ?", [$nowStr])
            ->whereRaw("TIMESTAMP(CONCAT(CAST(fecha AS CHAR), ' ', CAST(hora_inicio AS CHAR))) <  ?", [$nowStr])
            ->update(['estado' => 'CERRADO']);

        return [$started, $closed];
    }
}
