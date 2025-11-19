<?php

namespace App\Services\Vm;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class SesionBatchService
{
    /**
     * Crea sesiones sin concatenar fecha+hora en PHP.
     */
    public static function createFor($sessionable, array $data): Collection
    {
        $horaInicio = self::normTime((string)$data['hora_inicio']); // H:i:s
        $horaFin    = self::normTime((string)$data['hora_fin']);    // H:i:s

        $make = function (string $fecha) use ($sessionable, $horaInicio, $horaFin) {
            return $sessionable->sesiones()->firstOrCreate([
                'fecha'       => $fecha,      // YYYY-MM-DD
                'hora_inicio' => $horaInicio, // H:i:s
                'hora_fin'    => $horaFin,    // H:i:s
            ], [
                'estado' => 'PLANIFICADO',
            ]);
        };

        $rows = collect();

        if (($data['mode'] ?? 'list') === 'range') {
            $fi = Carbon::parse($data['fecha_inicio'])->startOfDay();
            $ff = Carbon::parse($data['fecha_fin'])->startOfDay();

            $map = [
                'DO'=>0,'DOM'=>0,'SUN'=>0, 0=>0,
                'LU'=>1,'LUN'=>1,'MON'=>1, 1=>1,
                'MA'=>2,'MAR'=>2,'TUE'=>2, 2=>2,
                'MI'=>3,'MIE'=>3,'WED'=>3, 3=>3,
                'JU'=>4,'JUE'=>4,'THU'=>4, 4=>4,
                'VI'=>5,'VIE'=>5,'FRI'=>5, 5=>5,
                'SA'=>6,'SAB'=>6,'SAT'=>6, 6=>6,
            ];
            $dias = collect($data['dias_semana'] ?? [])->map(function ($v) use ($map) {
                $k = is_int($v) ? $v : strtoupper($v);
                return $map[$k] ?? null;
            })->filter(fn($v) => $v !== null)->unique()->values();

            foreach (CarbonPeriod::create($fi, $ff) as $day) {
                if ($dias->isNotEmpty() && !$dias->contains($day->dayOfWeek)) continue;
                $rows->push($make($day->toDateString()));
            }
        } else { // mode=list
            foreach ((array)($data['fechas'] ?? []) as $fecha) {
                $rows->push($make(Carbon::parse($fecha)->toDateString()));
            }
        }

        return $rows;
    }

    /** Normaliza H:i → H:i:s y tolera H:i:s / 8:00. */
    protected static function normTime(string $t): string
    {
        $s = trim($t);
        if (preg_match('/^\d{1}:\d{2}$/', $s)) $s = '0'.$s;     // 8:00 → 08:00
        if (preg_match('/^\d{2}:\d{2}$/', $s)) return $s.':00'; // HH:mm → HH:mm:00
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $s)) return $s; // HH:mm:ss
        try { return Carbon::parse($s)->format('H:i:s'); } catch (\Throwable) { return $s; }
    }
}
