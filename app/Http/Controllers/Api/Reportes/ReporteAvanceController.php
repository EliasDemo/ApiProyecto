<?php

namespace App\Http\Controllers\Api\Reportes;

use App\Http\Controllers\Controller;
use App\Models\ExpedienteAcademico;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReporteAvanceController extends Controller
{
    /**
     * GET /api/reportes/horas/mias/por-proyecto
     * Params:
     * - estado=APROBADO | *  (default APROBADO)
     * - periodo_id=ID      (opcional)
     * - debug=1            (opcional; requiere APP_DEBUG=true para ver detalle)
     */
    public function miAvancePorProyecto(Request $request): JsonResponse
    {
        $debug = (bool) ($request->boolean('debug') || config('app.debug'));
        $diag  = ['step' => 'init', 'input' => $request->query()];

        try {
            $user = $request->user();
            if (!$user || !$user->can(EpScopeService::PERM_VIEW_EXPEDIENTE)) {
                return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);
            }

            // ====== Resolver columna de usuario (user_id vs usuario_id) ======
            $userCols = [];
            if (Schema::hasColumn('expedientes_academicos', 'user_id'))     $userCols[] = 'user_id';
            if (Schema::hasColumn('expedientes_academicos', 'usuario_id'))  $userCols[] = 'usuario_id';
            if (empty($userCols)) {
                $msg = 'No se encontró ninguna columna de usuario en expedientes_academicos (user_id/usuario_id).';
                Log::error("[miAvancePorProyecto] {$msg}");
                return $this->failDiag($msg, $debug, $diag);
            }

            // ====== Buscar expediente ACTIVO del usuario ======
            $expQ = ExpedienteAcademico::query()->select('id');
            if (Schema::hasColumn('expedientes_academicos', 'estado')) {
                $expQ->where('estado', 'ACTIVO');
            }

            $expQ->where(function ($q) use ($userCols, $user) {
                foreach ($userCols as $i => $col) {
                    $i === 0 ? $q->where($col, $user->id) : $q->orWhere($col, $user->id);
                }
            });

            $exp = $expQ->latest('id')->first();

            if (!$exp) {
                return response()->json(['ok'=>false,'message'=>'EXPEDIENTE_NO_ENCONTRADO'], 404);
            }

            $expedienteId = (int) $exp->id;
            $estado       = (string) ($request->get('estado') ?? 'APROBADO');
            $periodoId    = $request->filled('periodo_id') ? (int)$request->get('periodo_id') : null;
            $diag += compact('expedienteId','estado','periodoId','userCols');

            // ====== Esquema mínimo requerido ======
            $needs = [
                ['registro_horas', ['expediente_id','periodo_id','vinculable_type','vinculable_id','minutos','estado']],
                ['vm_procesos',     ['id','proyecto_id']],
                ['vm_proyectos',    ['id','titulo','horas_planificadas']],
            ];
            foreach ($needs as [$table, $cols]) {
                if (!Schema::hasTable($table)) {
                    $msg = "Tabla requerida ausente: {$table}";
                    Log::error("[miAvancePorProyecto] {$msg}");
                    return $this->failDiag($msg, $debug, $diag + ['missing_table'=>$table]);
                }
                foreach ($cols as $c) {
                    if (!Schema::hasColumn($table, $c)) {
                        $msg = "Columna requerida ausente: {$table}.{$c}";
                        Log::error("[miAvancePorProyecto] {$msg}");
                        return $this->failDiag($msg, $debug, $diag + ['missing_column'=>"$table.$c"]);
                    }
                }
            }

            // Normaliza tipos de morph (alias + FQCN + variantes)
            $typesProyecto = array_values(array_filter(array_unique([
                'vm_proyecto',
                'App\\Models\\VmProyecto',
                class_exists(\App\Models\VmProyecto::class) ? \App\Models\VmProyecto::class : null,
                'VmProyecto','vmProyecto','VM_PROYECTO',
            ])));
            $typesProceso = array_values(array_filter(array_unique([
                'vm_proceso',
                'App\\Models\\VmProceso',
                class_exists(\App\Models\VmProceso::class) ? \App\Models\VmProceso::class : null,
                'VmProceso','vmProceso','VM_PROCESO',
            ])));

            $rh = app(\App\Models\RegistroHora::class)->getTable();

            // 0) Si no hay filas del expediente (y periodo), devuelve vacío
            $existsQ = DB::table($rh.' as e0')
                ->where('e0.expediente_id', $expedienteId)
                ->when($periodoId, fn($q)=>$q->where('e0.periodo_id',$periodoId))
                ->limit(1)
                ->exists();

            if (!$existsQ) {
                return response()->json([
                    'ok'=>true,
                    'data'=>['por_proyecto'=>[], 'total_minutos'=>0, 'total_horas'=>0],
                ]);
            }

            // 1) Sumas directas a proyecto
            $directosRows = DB::table($rh.' as rh')
                ->where('rh.expediente_id', $expedienteId)
                ->whereIn('rh.vinculable_type', $typesProyecto)
                ->when($estado !== '*', fn($q) => $q->where('rh.estado', $estado))
                ->when($periodoId, fn($q) => $q->where('rh.periodo_id', $periodoId))
                ->where('rh.vinculable_id', '>', 0)
                ->selectRaw('rh.vinculable_id as proyecto_id, SUM(rh.minutos) as min')
                ->groupBy('rh.vinculable_id')
                ->get();

            // 2) Sumas desde procesos → proyecto_id
            $desdeProcRows = DB::table($rh.' as rh')
                ->join('vm_procesos as pr', 'pr.id', '=', 'rh.vinculable_id')
                ->where('rh.expediente_id', $expedienteId)
                ->whereIn('rh.vinculable_type', $typesProceso)
                ->when($estado !== '*', fn($q) => $q->where('rh.estado', $estado))
                ->when($periodoId, fn($q) => $q->where('rh.periodo_id', $periodoId))
                ->whereNotNull('pr.proyecto_id')
                ->where('pr.proyecto_id', '>', 0)
                ->selectRaw('pr.proyecto_id as proyecto_id, SUM(rh.minutos) as min')
                ->groupBy('pr.proyecto_id')
                ->get();

            // 3) Merge en PHP
            $sumas = [];
            foreach ($directosRows as $r) {
                $pid = (int) $r->proyecto_id;
                $sumas[$pid] = ($sumas[$pid] ?? 0) + (int) $r->min;
            }
            foreach ($desdeProcRows as $r) {
                $pid = (int) $r->proyecto_id;
                $sumas[$pid] = ($sumas[$pid] ?? 0) + (int) $r->min;
            }

            if (empty($sumas)) {
                return response()->json([
                    'ok'=>true,
                    'data'=>['por_proyecto'=>[], 'total_minutos'=>0, 'total_horas'=>0],
                ]);
            }

            // 4) Títulos y metas
            $proyectoIds = array_keys($sumas);
            $proyectos = DB::table('vm_proyectos')
                ->whereIn('id', $proyectoIds)
                ->select('id','titulo','horas_planificadas')
                ->get()
                ->keyBy('id');

            $porProyecto = [];
            $totalMin = 0;

            foreach ($proyectoIds as $pid) {
                $min = (int) ($sumas[$pid] ?? 0);
                $p   = $proyectos->get($pid);

                if (!$p) {
                    Log::warning('[miAvancePorProyecto] Proyecto huérfano omitido', ['proyecto_id'=>$pid]);
                    continue;
                }

                $reqHoras = $p->horas_planificadas ?? null;
                $reqMin   = $reqHoras ? (int)$reqHoras * 60 : null;

                $pct = null;
                if ($reqMin && $reqMin > 0) {
                    $pct = (int) round(min(100, max(0, ($min / $reqMin) * 100)));
                }

                $porProyecto[] = [
                    'id'                  => (int)$pid,
                    'titulo'              => $p->titulo ?? null,
                    'minutos'             => $min,
                    'horas'               => round($min / 60, 2),
                    'horas_planificadas'  => $reqHoras,
                    'minutos_requeridos'  => $reqMin,
                    'minutos_faltantes'   => $reqMin ? max(0, $reqMin - $min) : null,
                    'porcentaje'          => $pct,
                ];

                $totalMin += $min;
            }

            // Orden por minutos
            usort($porProyecto, fn($a,$b) => $b['minutos'] <=> $a['minutos']);

            $payload = [
                'ok'   => true,
                'data' => [
                    'por_proyecto'  => $porProyecto,
                    'total_minutos' => $totalMin,
                    'total_horas'   => round($totalMin/60, 2),
                ],
            ];

            if ($debug) {
                $payload['diag'] = [
                    'types_proyecto' => array_values($typesProyecto),
                    'types_proceso'  => array_values($typesProceso),
                    'counts' => [
                        'directos'     => count($directosRows),
                        'desde_proc'   => count($desdeProcRows),
                        'merge_final'  => count($porProyecto),
                    ],
                    'user_cols' => $userCols,
                ];
            }

            return response()->json($payload);

        } catch (\Throwable $e) {
            Log::error('[miAvancePorProyecto] '.$e->getMessage(), [
                'trace'   => $e->getTraceAsString(),
                'user_id' => optional($request->user())->id,
                'query'   => $request->query(),
            ]);

            if ($debug) {
                $err = [
                    'ok'=>false,
                    'message'=>'INTERNAL_ERROR',
                    'error'=>[
                        'msg'=>$e->getMessage(),
                        'code'=>method_exists($e,'getCode') ? $e->getCode() : null,
                    ],
                    'diag'=>$diag + ['step'=>'catch'],
                ];
                if (property_exists($e,'errorInfo') && is_array($e->errorInfo)) {
                    $err['error']['sqlstate']    = $e->errorInfo[0] ?? null;
                    $err['error']['driver_code'] = $e->errorInfo[1] ?? null;
                    $err['error']['driver_msg']  = $e->errorInfo[2] ?? null;
                }
                return response()->json($err, 500);
            }

            return response()->json(['ok'=>false,'message'=>'INTERNAL_ERROR'], 500);
        }
    }

    private function failDiag(string $msg, bool $debug, array $diag): JsonResponse
    {
        if ($debug) {
            return response()->json(['ok'=>false,'message'=>$msg,'diag'=>$diag], 500);
        }
        return response()->json(['ok'=>false,'message'=>'INTERNAL_ERROR'], 500);
    }
}
