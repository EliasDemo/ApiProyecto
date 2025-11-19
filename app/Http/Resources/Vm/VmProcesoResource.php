<?php

namespace App\Http\Resources\Vm;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class VmProcesoResource extends JsonResource
{
    public function toArray($request): array
    {
        // minutos sesiones si la relación viene cargada (no forzamos N+1)
        $minSesiones = null;
        if ($this->relationLoaded('sesiones')) {
            $minSesiones = $this->sesiones->reduce(function ($acc, $s) {
                // evitar "Double time specification"
                $fecha = $s->fecha instanceof CarbonInterface ? $s->fecha->toDateString() : (string) $s->fecha;
                $hi = (string) $s->hora_inicio;
                $hf = (string) $s->hora_fin;
                try {
                    $ini = Carbon::parse($fecha)->setTimeFromTimeString($hi);
                    $fin = Carbon::parse($fecha)->setTimeFromTimeString($hf);
                    return $acc + max(0, $ini->diffInMinutes($fin, false));
                } catch (\Throwable) {
                    return $acc;
                }
            }, 0);
        }

        $minAsignados = $this->horas_asignadas !== null ? ((int)$this->horas_asignadas) * 60 : null;

        $minutosCache = $this->minutos_cache ?? [
            'asignados' => $minAsignados,
            'sesiones'  => $minSesiones,
        ];

        return [
            'id' => $this->id,
            'proyecto_id' => $this->proyecto_id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'tipo_registro' => $this->tipo_registro,
            'horas_asignadas' => $this->horas_asignadas ? (int) $this->horas_asignadas : null,
            'nota_minima' => $this->nota_minima ? (int) $this->nota_minima : null,
            'requiere_asistencia' => (bool) $this->requiere_asistencia,
            'orden' => $this->orden ? (int) $this->orden : null,
            'estado' => $this->estado,
            'created_at' => $this->created_at?->toDateTimeString(),

            'minutos_cache' => $minutosCache,

            'sesiones' => VmSesionResource::collection($this->whenLoaded('sesiones')),
        ];
    }
}
