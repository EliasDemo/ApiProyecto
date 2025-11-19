<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VmSesion;
use App\Services\Vm\EstadoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VmTickCommand extends Command
{
    protected $signature = 'vm:tick';
    protected $description = 'Actualiza estados de sesiones/procesos/proyectos/eventos según fecha y hora';

    public function handle(EstadoService $estadoService): int
    {
        $now = now();
        $nowStr = $now->format('Y-m-d H:i:s');

        $this->info("vm:tick @ {$nowStr}");
        $failed = 0;

        try {
            // 1) PLANIFICADO → EN_CURSO
            $this->processTransition(
                function ($q) use ($nowStr) {
                    $q->where('estado', 'PLANIFICADO')
                      ->whereRaw("TIMESTAMP(CONCAT(fecha,' ',hora_inicio)) <= ?", [$nowStr])
                      ->whereRaw("TIMESTAMP(CONCAT(fecha,' ',hora_fin)) >  ?", [$nowStr]);
                },
                'EN_CURSO',
                'START',
                $estadoService,
                $nowStr,
                $failed
            );

            // 2) PLANIFICADO|EN_CURSO → CERRADO
            $this->processTransition(
                function ($q) use ($nowStr) {
                    $q->whereIn('estado', ['PLANIFICADO', 'EN_CURSO'])
                      ->whereRaw("TIMESTAMP(CONCAT(fecha,' ',hora_fin)) <= ?", [$nowStr])
                      ->whereRaw("TIMESTAMP(CONCAT(fecha,' ',hora_inicio)) <  ?", [$nowStr]);
                },
                'CERRADO',
                'CLOSE',
                $estadoService,
                $nowStr,
                $failed
            );

        } catch (\Throwable $e) {
            $this->error('vm:tick FALLÓ: '.$e->getMessage());
            Log::error('[vm:tick] FALLÓ', ['now' => $nowStr, 'msg' => $e->getMessage()]);
            return self::FAILURE;
        }

        if ($failed > 0) {
            $this->warn("vm:tick terminó con {$failed} error(es). Revisa los logs.");
        } else {
            $this->info('vm:tick OK');
        }

        return self::SUCCESS;
    }

    /**
     * Aplica una transición de estado sobre VmSesion en chunks.
     *
     * @param  callable       $constraints  Recibe el query builder de VmSesion
     * @param  string         $toState      Estado destino ('EN_CURSO', 'CERRADO', ...)
     * @param  string         $actionLabel  Etiqueta para logs ('START', 'CLOSE', ...)
     */
    private function processTransition(
        callable $constraints,
        string $toState,
        string $actionLabel,
        EstadoService $estadoService,
        string $nowStr,
        int &$failed
    ): void {
        VmSesion::query()
            ->tap($constraints)
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use ($estadoService, $toState, $actionLabel, $nowStr, &$failed) {
                foreach ($chunk as $s) {
                    try {
                        $old = $s->estado;

                        DB::transaction(function () use ($s, $estadoService, $toState, $actionLabel, $nowStr, $old) {
                            $s->update(['estado' => $toState]);
                            $estadoService->recalcOwner($s->sessionable);

                            Log::info("[vm:tick] Sesión {$actionLabel}", [
                                'sesion_id'   => $s->id,
                                'owner'       => class_basename($s->sessionable_type).':'.$s->sessionable_id,
                                'from'        => $old,
                                'to'          => $toState,
                                'fecha'       => (string)$s->fecha,
                                'hora_inicio' => (string)$s->hora_inicio,
                                'hora_fin'    => (string)$s->hora_fin,
                                'now'         => $nowStr,
                            ]);
                        }, 3);
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::error("[vm:tick] Error al {$actionLabel} sesión", [
                            'sesion_id' => $s->id,
                            'msg'       => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
