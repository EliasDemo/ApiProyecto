<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class DateList
{
    /**
     * Expande el payload batch a una lista de fechas (YYYY-MM-DD).
     * @param  array $data
     * @return \Illuminate\Support\Collection<string>
     */
    public static function fromBatchPayload(array $data): Collection
    {
        $mode = (string) ($data['mode'] ?? 'list');

        if ($mode === 'list') {
            $fechas = collect($data['fechas'] ?? [])
                ->filter(fn($d) => !is_null($d) && $d !== '')
                ->map(function ($d) {
                    try { return Carbon::parse($d)->toDateString(); }
                    catch (\Throwable) { return null; }
                })
                ->filter() // quita nulls
                ->unique()
                ->values();

            return $fechas;
        }

        // mode === 'range'
        $fiRaw = $data['fecha_inicio'] ?? null;
        $ffRaw = $data['fecha_fin'] ?? null;

        if (!$fiRaw || !$ffRaw) {
            return collect(); // payload incompleto
        }

        try {
            $fi = Carbon::parse($fiRaw)->startOfDay();
            $ff = Carbon::parse($ffRaw)->startOfDay();
        } catch (\Throwable) {
            return collect();
        }

        // Normaliza días de semana (acepta 'LU'..'DO', 'MON'..'SUN', 0..6)
        $map = [
            'DO'=>0,'DOM'=>0,'SUN'=>0, 0=>0,
            'LU'=>1,'LUN'=>1,'MON'=>1, 1=>1,
            'MA'=>2,'MAR'=>2,'TUE'=>2, 2=>2,
            'MI'=>3,'MIE'=>3,'WED'=>3, 3=>3,
            'JU'=>4,'JUE'=>4,'THU'=>4, 4=>4,
            'VI'=>5,'VIE'=>5,'FRI'=>5, 5=>5,
            'SA'=>6,'SAB'=>6,'SAT'=>6, 6=>6,
        ];

        $diasSemana = collect($data['dias_semana'] ?? [])
            ->map(function ($v) use ($map) {
                $k = is_int($v) ? $v : strtoupper((string)$v);
                return $map[$k] ?? null;
            })
            ->filter(fn($v) => $v !== null)
            ->unique()
            ->values();

        $out = collect();
        foreach (CarbonPeriod::create($fi, $ff) as $day) {
            /** @var CarbonInterface $day */
            if ($diasSemana->isNotEmpty() && !$diasSemana->contains($day->dayOfWeek)) {
                continue;
            }
            $out->push($day->toDateString());
        }

        return $out->unique()->values();
    }
}
