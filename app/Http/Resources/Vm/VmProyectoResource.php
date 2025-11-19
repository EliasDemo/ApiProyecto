<?php

namespace App\Http\Resources\Vm;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Vm\ImagenResource as VmImagenResource;

class VmProyectoResource extends JsonResource
{
    public function toArray($request): array
    {
        $this->resource->loadMissing(['ciclos', 'imagenes']);

        $firstImg = $this->imagenes->first();

        // minutos "calculados" por defecto
        $minPlan   = ((int) $this->horas_planificadas) * 60;
        $minMinimo = $this->horas_minimas_participante !== null
            ? ((int) $this->horas_minimas_participante) * 60
            : null;

        // si el modelo ya trae un cache real (columna JSON), se respeta
        $minutosCache = $this->minutos_cache ?? [
            'plan'               => $minPlan,
            'minimo_participante'=> $minMinimo,
            // 'validados' => null, // si quieres exponerlo, completa desde servicios
        ];

        return [
            'id'          => $this->id,
            'codigo'      => $this->codigo,
            'titulo'      => $this->titulo,
            'tipo'        => $this->tipo,
            'modalidad'   => $this->modalidad,
            'estado'      => $this->estado,
            'descripcion' => $this->descripcion,

            // 👇 Multiciclo
            'niveles'     => $this->ciclos->pluck('nivel')->values(),

            'ep_sede_id'  => $this->ep_sede_id,
            'periodo_id'  => $this->periodo_id,

            'horas_planificadas'          => (int) $this->horas_planificadas,
            'horas_minimas_participante'  => $this->horas_minimas_participante !== null
                                                ? (int) $this->horas_minimas_participante
                                                : null,

            // 👇 NUEVO
            'minutos_cache' => $minutosCache,

            'created_at'  => $this->created_at?->toDateTimeString(),

            // Portada e imágenes
            'cover_url'      => $firstImg ? $firstImg->url_publica : null,
            'imagenes'       => VmImagenResource::collection($this->imagenes),
            'imagenes_total' => $this->imagenes_total ?? $this->imagenes->count(),
        ];
    }
}
