<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AgendaController extends Controller
{
    // GET /api/vm/alumno/agenda
    public function agendaAlumno(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        $periodoId = $request->integer('periodo_id');

        // 1) Expediente activo del alumno
        $exp = DB::table('expedientes_academicos')
            ->where('user_id', $user->id)
            ->where('estado', 'ACTIVO')
            ->first();

        if (!$exp) {
            return response()->json(['ok' => true, 'data' => []]);
        }

        // 2) Periodo actual si no viene por query
        if (!$periodoId) {
            $periodoId = (int) (DB::table('periodos_academicos')->where('es_actual', 1)->value('id') ?? 0);
        }

        // 3) Proyectos donde está inscrito
        $participaciones = DB::table('vm_participaciones')
            ->select('participable_id')
            ->where('participable_type', \App\Models\VmProyecto::class)
            ->where('expediente_id', $exp->id)
            ->whereIn('estado', ['INSCRITO','CONFIRMADO'])
            ->pluck('participable_id')
            ->all();

        if (!$participaciones) {
            return response()->json(['ok' => true, 'data' => []]);
        }

        // 4) Proyectos del periodo (si hay periodo)
        $proyectosQ = DB::table('vm_proyectos')->whereIn('id', $participaciones);
        if ($periodoId) {
            $proyectosQ->where('periodo_id', $periodoId);
        }
        $proyectos = $proyectosQ->get();

        $respuesta = [];

        foreach ($proyectos as $proy) {
            // Niveles del proyecto (multiciclo)
            $niveles = DB::table('vm_proyecto_ciclos')
                ->where('proyecto_id', $proy->id)
                ->pluck('nivel')
                ->map(fn($n)=>(int)$n)
                ->values()
                ->all();

            // Procesos del proyecto
            $procesos = DB::table('vm_procesos')
                ->where('proyecto_id', $proy->id)
                ->orderBy('orden')
                ->get();

            $procesosResp = [];

            foreach ($procesos as $proc) {
                // Sesiones del proceso
                $sesiones = DB::table('vm_sesiones')
                    ->where('sessionable_type', \App\Models\VmProceso::class)
                    ->where('sessionable_id', $proc->id)
                    ->orderBy('fecha')->orderBy('hora_inicio')
                    ->get();

                $sesionesResp = [];
                $minTotal = 0;
                $minVal   = 0;
                $asistidas = 0;
                $faltadas  = 0;

                foreach ($sesiones as $ses) {
                    $inicio = Carbon::parse($ses->fecha.' '.$ses->hora_inicio);
                    $fin    = Carbon::parse($ses->fecha.' '.$ses->hora_fin);
                    $durMin = max(0, $inicio->diffInMinutes($fin, false));
                    $minTotal += $durMin;

                    // Estado relativo
                    if (Carbon::now()->lt($inicio)) {
                        $estadoRel = 'PROXIMA';
                    } elseif (Carbon::now()->between($inicio, $fin)) {
                        $estadoRel = 'ACTUAL';
                    } else {
                        $estadoRel = 'PASADA';
                    }

                    // Asistencia del alumno en esa sesión
                    $asis = DB::table('vm_asistencias')
                        ->where('sesion_id', $ses->id)
                        ->where('expediente_id', $exp->id)
                        ->first();

                    if ($asis) {
                        $minVal += (int) $asis->minutos_validados;
                        if ($asis->estado === 'VALIDADO') $asistidas++;
                        $asisOut = [
                            'estado'      => $asis->estado,
                            'metodo'      => $asis->metodo,
                            'check_in_at' => optional($asis->check_in_at)->format('Y-m-d H:i:s'),
                        ];
                    } else {
                        if ($estadoRel === 'PASADA') $faltadas++;
                        $asisOut = ['estado' => 'SIN_REGISTRO', 'metodo' => null, 'check_in_at' => null];
                    }

                    $sesionesResp[] = [
                        'sesion' => [
                            'id' => (int)$ses->id,
                            'sessionable_type' => $ses->sessionable_type,
                            'sessionable_id'   => (int)$ses->sessionable_id,
                            'fecha' => $ses->fecha,
                            'hora_inicio' => $ses->hora_inicio,
                            'hora_fin'    => $ses->hora_fin,
                            'estado'      => $ses->estado,
                            'created_at'  => null,
                        ],
                        'estado_relativo' => $estadoRel,
                        'asistencia'      => $asisOut,
                    ];
                }

                $procesosResp[] = [
                    'proceso' => [
                        'id' => (int)$proc->id,
                        'proyecto_id' => (int)$proc->proyecto_id,
                        'nombre' => $proc->nombre,
                        'descripcion' => $proc->descripcion,
                        'tipo_registro' => $proc->tipo_registro,
                        'horas_asignadas' => $proc->horas_asignadas,
                        'nota_minima' => $proc->nota_minima,
                        'requiere_asistencia' => (bool)$proc->requiere_asistencia,
                        'orden' => $proc->orden,
                        'estado' => $proc->estado,
                        'created_at' => null,
                    ],
                    'progreso' => [
                        'min_total' => $minTotal,
                        'min_validados' => $minVal,
                        'min_pendientes' => max(0, $minTotal - $minVal),
                        'sesiones_total' => count($sesionesResp),
                        'sesiones_asistidas' => $asistidas,
                        'sesiones_faltadas'  => $faltadas,
                    ],
                    'sesiones' => $sesionesResp,
                ];
            }

            $respuesta[] = [
                'proyecto' => [
                    'id'    => (int)$proy->id,
                    'codigo'=> $proy->codigo,
                    'titulo'=> $proy->titulo,
                    'tipo'  => $proy->tipo,
                    'modalidad' => $proy->modalidad,
                    'estado'=> $proy->estado,
                    'niveles' => $niveles, // 👈 multiciclo
                    'ep_sede_id' => (int)$proy->ep_sede_id,
                    'periodo_id' => (int)$proy->periodo_id,
                    'horas_planificadas' => (int)$proy->horas_planificadas,
                    'horas_minimas_participante' => $proy->horas_minimas_participante,
                    'created_at' => null,
                ],
                'procesos' => $procesosResp,
            ];
        }

        return response()->json(['ok' => true, 'data' => $respuesta]);
    }

    // GET /api/vm/staff/agenda
    public function agendaStaff(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }
        if (!$user->can('ep.manage.ep_sede')) {
            return response()->json(['ok' => false, 'message' => 'NO_AUTORIZADO'], 403);
        }

        $nivel = $request->integer('nivel') ?: null;
        $periodoId = $request->integer('periodo_id');

        $epSedes = DB::table('expedientes_academicos')
            ->where('user_id', $user->id)
            ->where('estado', 'ACTIVO')
            ->whereIn('rol', ['COORDINADOR','ENCARGADO'])
            ->pluck('ep_sede_id')
            ->all();

        if (!$epSedes) {
            return response()->json(['ok' => true, 'data' => []]);
        }

        if (!$periodoId) {
            $periodoId = (int) (DB::table('periodos_academicos')->where('es_actual', 1)->value('id') ?? 0);
        }

        $sesionesQ = DB::table('vm_sesiones as s')
            ->join('vm_procesos as p', function ($j) {
                $j->on('p.id', '=', 's.sessionable_id')
                  ->where('s.sessionable_type', '=', \App\Models\VmProceso::class);
            })
            ->join('vm_proyectos as pr', 'pr.id', '=', 'p.proyecto_id')
            ->whereIn('pr.ep_sede_id', $epSedes);

        if ($periodoId) $sesionesQ->where('pr.periodo_id', $periodoId);

        // Filtro por nivel (multiciclo) a nivel de proyecto
        if ($nivel !== null) {
            $sesionesQ->join('vm_proyecto_ciclos as pc', 'pc.proyecto_id', '=', 'pr.id')
                      ->where('pc.nivel', $nivel);
        }

        $sesionesQ->whereDate('s.fecha', '>=', Carbon::today()->toDateString());

        $sesiones = $sesionesQ
            ->select(
                's.*',
                'p.id as proceso_id', 'p.nombre as proceso_nombre', 'p.tipo_registro',
                'pr.id as proyecto_id', 'pr.titulo as proyecto_titulo', 'pr.codigo as proyecto_codigo'
            )
            ->distinct()
            ->orderBy('s.fecha')->orderBy('s.hora_inicio')
            ->get();

        $cards = [];

        foreach ($sesiones as $row) {
            // inscritos (alumnos)
            $inscritos = (int) DB::table('vm_participaciones')
                ->where('participable_type', \App\Models\VmProyecto::class)
                ->where('participable_id', $row->proyecto_id)
                ->whereIn('estado', ['INSCRITO','CONFIRMADO'])
                ->count();

            // asistencias registradas
            $asistencias = (int) DB::table('vm_asistencias')
                ->where('sesion_id', $row->id)
                ->count();

            // niveles del proyecto
            $niveles = DB::table('vm_proyecto_ciclos')
                ->where('proyecto_id', $row->proyecto_id)
                ->pluck('nivel')
                ->map(fn($n)=>(int)$n)
                ->values()
                ->all();

            // QR activo
            $now = Carbon::now();
            $vqr = DB::table('vm_qr_tokens')
                ->where('sesion_id', $row->id)
                ->where('activo', 1)
                ->where(function($q){ $q->whereNull('max_usos')->orWhereColumn('usos', '<', 'max_usos'); })
                ->where(function($q) use ($now){ $q->whereNull('usable_from')->orWhere('usable_from','<=',$now); })
                ->where(function($q) use ($now){ $q->whereNull('expires_at')->orWhere('expires_at','>=',$now); })
                ->orderByDesc('id')
                ->first();

            $cards[] = [
                'sesion' => [
                    'id' => (int)$row->id,
                    'sessionable_type' => \App\Models\VmProceso::class,
                    'sessionable_id'   => (int)$row->proceso_id,
                    'fecha' => $row->fecha,
                    'hora_inicio' => $row->hora_inicio,
                    'hora_fin'    => $row->hora_fin,
                    'estado'      => $row->estado,
                    'created_at'  => null,
                ],
                'proyecto' => [
                    'id' => (int)$row->proyecto_id,
                    'codigo' => $row->proyecto_codigo,
                    'titulo' => $row->proyecto_titulo,
                    'niveles' => $niveles, // 👈 multiciclo
                ],
                'proceso' => [
                    'id' => (int)$row->proceso_id,
                    'proyecto_id' => (int)$row->proyecto_id,
                    'nombre' => $row->proceso_nombre,
                    'tipo_registro' => $row->tipo_registro,
                ],
                'inscritos'   => $inscritos,
                'asistencias' => $asistencias,
                'ventanas' => [
                    'qr' => $vqr ? [
                        'token' => $vqr->token,
                        'usable_from' => optional($vqr->usable_from)->format('Y-m-d H:i:s'),
                        'expires_at'  => optional($vqr->expires_at)->format('Y-m-d H:i:s'),
                        'geo' => null,
                    ] : null,
                    'manual' => null,
                ],
            ];
        }

        return response()->json(['ok' => true, 'data' => $cards]);
    }
}
