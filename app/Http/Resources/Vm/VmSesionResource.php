<?php

namespace App\Http\Resources\Vm;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class VmSesionResource extends JsonResource
{
    protected function normTime($v): ?string
    {
        if ($v === null || $v === '') return null;
        $s = (string) $v;
        // Ya viene en HH:mm
        if (preg_match('/^\d{2}:\d{2}$/', $s)) return $s;
        // HH:mm:ss -> HH:mm
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $s)) return substr($s, 0, 5);
        // 8:00 -> 08:00
        if (preg_match('/^\d{1}:\d{2}$/', $s)) return '0'.$s;
        // Intento genérico
        try { return Carbon::parse($s)->format('H:i'); } catch (\Throwable) { return $s; }
    }

    public function toArray($request): array
    {
        // Carga perezosa: no rompe si no se pasó with('ciclos')
        $this->resource->loadMissing('ciclos');

        $fechaVal = $this->fecha;
        $fecha = $fechaVal instanceof CarbonInterface ? $fechaVal->toDateString() : (string) $fechaVal;

        $createdVal = $this->created_at;
        $created = $createdVal instanceof CarbonInterface ? $createdVal->toDateTimeString() : (string) $createdVal;

        return [
            'id'               => $this->id,
            'sessionable_type' => $this->getRawOriginal('sessionable_type'),
            'sessionable_id'   => $this->sessionable_id,
            'fecha'            => $fecha,
            'hora_inicio'      => $this->normTime($this->hora_inicio), // 👈 HH:mm garantizado
            'hora_fin'         => $this->normTime($this->hora_fin),    // 👈 HH:mm garantizado
            'estado'           => $this->estado,

            // Multiciclo
            'niveles'          => $this->ciclos->pluck('nivel')->values(),

            'created_at'       => $created,
        ];
    }
}
