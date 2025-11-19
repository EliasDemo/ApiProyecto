<?php

namespace App\Services\Vm;

use App\Models\VmProyecto;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class PlanificacionService
{
    /**
     * Valida "cuadres":
     *  - Por proceso: suma de minutos de sus sesiones == horas_asignadas * 60 (si horas_asignadas no es null)
     *  - Proyecto: suma de horas_asignadas == horas_planificadas (si hay horas_asignadas definidas)
     *
     * @throws ValidationException 422 con detalles.
     */
    public function assertCuadres(VmProyecto $proyecto): void
    {
        $proyecto->loadMissing(['procesos.sesiones']);

        $errores = [];
        $sumHorasAsignadas = 0;

        foreach ($proyecto->procesos as $proceso) {
            $sumHorasAsignadas += (int) ($proceso->horas_asignadas ?? 0);

            $minAsignados = $proceso->horas_asignadas !== null
                ? ((int) $proceso->horas_asignadas) * 60
                : null;

            $minSesiones = 0;
            foreach ($proceso->sesiones as $s) {
                // ⚠️ evitar "Double time specification"
                $fecha = $s->fecha instanceof CarbonInterface ? $s->fecha->toDateString() : (string) $s->fecha;
                $ini   = Carbon::parse($fecha)->setTimeFromTimeString((string)$s->hora_inicio);
                $fin   = Carbon::parse($fecha)->setTimeFromTimeString((string)$s->hora_fin);
                $minSesiones += max(0, $ini->diffInMinutes($fin, false));
            }

            if ($minAsignados !== null && $minSesiones !== $minAsignados) {
                $errores['procesos'][] = [
                    'proceso_id'   => (int) $proceso->id,
                    'esperado_min' => $minAsignados,
                    'sesiones_min' => $minSesiones,
                    'delta_min'    => $minSesiones - $minAsignados,
                ];
            }
        }

        if ($sumHorasAsignadas > 0 && $sumHorasAsignadas !== (int) $proyecto->horas_planificadas) {
            $errores['proyecto'] = [
                'horas_planificadas' => (int) $proyecto->horas_planificadas,
                'horas_en_procesos'  => $sumHorasAsignadas,
                'delta_horas'        => $sumHorasAsignadas - (int) $proyecto->horas_planificadas,
            ];
        }

        if (!empty($errores)) {
            throw ValidationException::withMessages([
                'planificacion' => ['Descuadre entre horas asignadas y duración de sesiones.'],
                'detalles'      => $errores,
            ]);
        }
    }

    /**
     * Recalcula (en memoria) los minutos por proceso.
     * Si tienes columnas JSON de cache, acá podrías persistirlas.
     */
    public function recalcularYSincronizar(VmProyecto $proyecto): void
    {
        $proyecto->loadMissing(['procesos.sesiones']);

        foreach ($proyecto->procesos as $proceso) {
            $minSesiones = 0;
            foreach ($proceso->sesiones as $s) {
                // ⚠️ evitar "Double time specification"
                $fecha = $s->fecha instanceof CarbonInterface ? $s->fecha->toDateString() : (string) $s->fecha;
                $ini   = Carbon::parse($fecha)->setTimeFromTimeString((string)$s->hora_inicio);
                $fin   = Carbon::parse($fecha)->setTimeFromTimeString((string)$s->hora_fin);
                $minSesiones += max(0, $ini->diffInMinutes($fin, false));
            }

            // Cache "efímero" en runtime (si tienes columna JSON, aquí podrías persistirlo).
            $proceso->setAttribute('minutos_cache', [
                'sesiones'  => $minSesiones,
                'asignados' => $proceso->horas_asignadas !== null ? ((int)$proceso->horas_asignadas) * 60 : null,
            ]);
        }
    }
}
