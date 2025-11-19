<?php

namespace App\Http\Resources\Vm;

use Illuminate\Http\Resources\Json\JsonResource;

class VmEventoResource extends JsonResource
{
    public function toArray($request): array
    {
        $targetType   = $this->getRawOriginal('targetable_type');
        $firstSession = $this->relationLoaded('sesiones')
            ? $this->sesiones->first()
            : null;

        return [
            'id'                  => $this->id,
            'codigo'              => $this->codigo,
            'periodo_id'          => $this->periodo_id,
            'categoria_evento_id' => $this->categoria_evento_id,

            'titulo'             => $this->titulo,
            'subtitulo'          => $this->subtitulo,
            'descripcion_corta'  => $this->descripcion_corta,
            'descripcion_larga'  => $this->descripcion_larga,

            'modalidad'          => $this->modalidad,
            'lugar_detallado'    => $this->lugar_detallado,
            'url_imagen_portada' => $this->url_imagen_portada,
            'url_enlace_virtual' => $this->url_enlace_virtual,

            'targetable_type'    => $targetType,
            'targetable_id'      => $this->targetable_id,
            'ep_sede_id'         => $targetType === 'ep_sede'
                ? (int) $this->targetable_id
                : null,

            'requiere_inscripcion' => (bool) $this->requiere_inscripcion,
            'cupo_maximo'          => $this->cupo_maximo !== null
                ? (int) $this->cupo_maximo
                : null,
            'inscripcion_desde'    => $this->inscripcion_desde?->toDateString(),
            'inscripcion_hasta'    => $this->inscripcion_hasta?->toDateString(),

            'estado'      => $this->estado,
            'created_at'  => $this->created_at?->toDateTimeString(),
            'updated_at'  => $this->updated_at?->toDateTimeString(),

            // Campos "legacy" para listados simples: primera sesión
            'fecha'       => $firstSession
                ? $firstSession->fecha?->toDateString()
                : null,
            'hora_inicio' => $firstSession?->hora_inicio,
            'hora_fin'    => $firstSession?->hora_fin,

            // Relaciones
            'periodo' => $this->whenLoaded('periodo', function () {
                return [
                    'id'           => $this->periodo->id,
                    'anio'         => $this->periodo->anio,
                    'ciclo'        => $this->periodo->ciclo,
                    'estado'       => $this->periodo->estado,
                    'fecha_inicio' => $this->periodo->fecha_inicio?->toDateString(),
                    'fecha_fin'    => $this->periodo->fecha_fin?->toDateString(),
                ];
            }),

            'categoria' => $this->whenLoaded('categoria', function () {
                return [
                    'id'     => $this->categoria->id,
                    'nombre' => $this->categoria->nombre,
                    'color'  => $this->categoria->color ?? null,
                ];
            }),

            'sesiones' => $this->whenLoaded('sesiones', function () {
                return $this->sesiones->map(function ($sesion) {
                    return [
                        'id'          => $sesion->id,
                        'fecha'       => $sesion->fecha?->toDateString(),
                        'hora_inicio' => $sesion->hora_inicio,
                        'hora_fin'    => $sesion->hora_fin,
                        'estado'      => $sesion->estado,
                    ];
                });
            }),
        ];
    }
}
