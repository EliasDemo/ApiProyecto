<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Resources\Json\JsonResource;

class AcademicoLookupResource extends JsonResource
{
    public function toArray($request): array
    {
        $ep = $this->epSede;

        return [
            'escuela_profesional' => $ep?->escuelaProfesional?->nombre,
            'sede'                => $ep?->sede?->nombre,
            // Si quieres mostrar el ID, lo agregas:
            // 'expediente_id'       => $this->id,
        ];
    }
}
