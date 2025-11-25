<?php

namespace App\Http\Controllers\Api\Alumno;

use App\Http\Controllers\Controller;
use App\Models\{
    ExpedienteAcademico,
    PeriodoAcademico,
    VmEvento,
    VmProyecto,
    VmProceso,
    VmSesion,
    VmParticipacion,
    VmAsistencia
};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // ✅ seguridad extra: si por alguna razón entra sin auth, evitamos 500
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], 401);
        }

        // 1) Resolver expediente (EP_SEDE) del alumno
        $exp = ExpedienteAcademico::where('user_id', $user->id)
            ->where('estado', 'ACTIVO')
            ->latest('id')
            ->first();

        if (!$exp) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se encontró expediente ACTIVO.',
            ], 422);
        }

        $epSedeId = (int) $exp->ep_sede_id;

        // 2) Resolver período (query o vigente o último) SIN ternario anidado
        $periodoId = $request->integer('periodo_id');

        if ($periodoId) {
            $periodo = PeriodoAcademico::find($periodoId);
        } else {
            // primero periodo vigente
            $periodo = PeriodoAcademico::whereDate('fecha_inicio', '<=', now())
                ->whereDate('fecha_fin', '>=', now())
                ->first();

            // si no hay vigente, tomar el último por fecha_inicio
            if (!$periodo) {
                $periodo = PeriodoAcademico::orderByDesc('fecha_inicio')->first();
            }
        }

        if (!$periodo) {
            return response()->json([
                'ok'      => false,
                'message' => 'No hay periodos académicos definidos.',
            ], 422);
        }

        $hoy       = Carbon::now();
        $esVigente = $hoy->between(
            Carbon::parse($periodo->fecha_inicio),
            Carbon::parse($periodo->fecha_fin)
        );

        // 3) Contadores globales
        $proyectosInscritos = VmParticipacion::where('participable_type', VmProyecto::class)
            ->where('expediente_id', $exp->id)
            ->count();

        $horasMin = (int) VmAsistencia::where('estado', 'VALIDADO')
            ->where('expediente_id', $exp->id)
            ->sum('minutos_validados');

        // FALTAS: sesiones terminadas sin asistencia (proyectos/eventos)
        [$faltasEventos, $faltasProyectos] = $this->faltasPorTipo($exp->id);

        // 4) Eventos: INSCRITOS + INSCRIBIBLES
        $eventosInscritos    = $this->eventosInscritos($exp->id, $periodo->id);
        $eventosInscribibles = $this->eventosInscribibles($exp->id, $epSedeId, $periodo->id, $hoy);

        // 5) Proyectos: INSCRITOS + INSCRIBIBLES
        $proyectosIns  = $this->proyectosInscritos($exp->id, $epSedeId, $periodo->id);
        $proyectosInsc = $this->proyectosInscribibles($exp->id, $epSedeId, $periodo->id, $hoy);

        return response()->json([
            'ok'   => true,
            'data' => [
                'contexto' => [
                    'expediente_id' => (int) $exp->id,
                    'ep_sede_id'    => $epSedeId,
                    'periodo'       => [
                        'id'      => (int) $periodo->id,
                        'codigo'  => $periodo->codigo ?? $periodo->id,
                        'inicio'  => $periodo->fecha_inicio,
                        'fin'     => $periodo->fecha_fin,
                        'vigente' => $esVigente,
                    ],
                    'ahora' => $hoy->format('Y-m-d H:i:s'),
                ],
                'contadores' => [
                    'proyectos_inscritos' => (int) $proyectosInscritos,
                    'horas_validadas_min' => (int) $horasMin,
                    'horas_validadas_h'   => round($horasMin / 60, 2),
                    'faltas_total'        => (int) ($faltasEventos + $faltasProyectos),
                    'faltas_eventos'      => (int) $faltasEventos,
                    'faltas_proyectos'    => (int) $faltasProyectos,
                ],
                'eventos' => [
                    'inscritos'    => $eventosInscritos,
                    'inscribibles' => $eventosInscribibles,
                ],
                'proyectos' => [
                    'inscritos'    => $proyectosIns,
                    'inscribibles' => $proyectosInsc,
                ],
            ],
        ], 200);
    }

    /* ───────────────── helpers ───────────────── */

    private function faltasPorTipo(int $expedienteId): array
    {
        $now   = Carbon::now()->format('H:i:s');
        $today = Carbon::today()->toDateString();

        // Eventos: sesiones vencidas sin asistencia
        $faltasEventos = DB::table('vm_sesiones as s')
            ->join('vm_eventos as e', function ($j) {
                $j->on('e.id', '=', 's.sessionable_id')
                  ->where('s.sessionable_type', VmEvento::class);
            })
            ->join('vm_participaciones as p', function ($j) use ($expedienteId) {
                $j->on('p.participable_id', '=', 'e.id')
                  ->where('p.participable_type', VmEvento::class)
                  ->where('p.expediente_id', $expedienteId)
                  ->whereIn('p.estado', ['INSCRITO', 'CONFIRMADO']);
            })
            ->leftJoin('vm_asistencias as a', function ($j) use ($expedienteId) {
                $j->on('a.sesion_id', '=', 's.id')
                  ->where('a.expediente_id', $expedienteId);
            })
            ->where(function ($q) use ($today, $now) {
                $q->whereDate('s.fecha', '<', $today)
                  ->orWhere(function ($qq) use ($today, $now) {
                      $qq->whereDate('s.fecha', $today)
                         ->where('s.hora_fin', '<', $now);
                  });
            })
            ->whereNull('a.id')
            ->count();

        // Proyectos: sesiones vencidas sin asistencia
        $faltasProyectos = DB::table('vm_sesiones as s')
            ->join('vm_procesos as pr', function ($j) {
                $j->on('pr.id', '=', 's.sessionable_id')
                  ->where('s.sessionable_type', VmProceso::class);
            })
            ->join('vm_proyectos as pjt', 'pjt.id', '=', 'pr.proyecto_id')
            ->join('vm_participaciones as part', function ($j) use ($expedienteId) {
                $j->on('part.participable_id', '=', 'pjt.id')
                  ->where('part.participable_type', VmProyecto::class)
                  ->where('part.expediente_id', $expedienteId)
                  ->whereIn('part.estado', ['INSCRITO', 'CONFIRMADO']);
            })
            ->leftJoin('vm_asistencias as a', function ($j) use ($expedienteId) {
                $j->on('a.sesion_id', '=', 's.id')
                  ->where('a.expediente_id', $expedienteId);
            })
            ->where(function ($q) use ($today, $now) {
                $q->whereDate('s.fecha', '<', $today)
                  ->orWhere(function ($qq) use ($today, $now) {
                      $qq->whereDate('s.fecha', $today)
                         ->where('s.hora_fin', '<', $now);
                  });
            })
            ->whereNull('a.id')
            ->count();

        return [(int) $faltasEventos, (int) $faltasProyectos];
    }

    private function eventosInscritos(int $expedienteId, int $periodoId): array
    {
        $rows = VmEvento::with([
                'sesiones' => fn ($q) => $q->orderBy('fecha')->orderBy('hora_inicio'),
                'imagenes' => fn ($q) => $q->latest(),
            ])
            ->where('periodo_id', $periodoId)
            ->whereHas('participaciones', fn ($q) => $q
                ->where('expediente_id', $expedienteId)
                ->whereIn('estado', ['INSCRITO', 'CONFIRMADO', 'FINALIZADO'])
            )
            ->orderByDesc('id')
            ->get();

        // progreso: asistencias validadas vs total sesiones
        $asis = DB::table('vm_asistencias')
            ->where('expediente_id', $expedienteId)
            ->pluck('estado', 'sesion_id');

        return $rows->map(function (VmEvento $e) use ($asis) {
            $tot    = $e->sesiones->count();
            $asisOk = $e->sesiones
                ->filter(fn ($s) => ($asis[$s->id] ?? null) === 'VALIDADO')
                ->count();

            return [
                'id'        => (int) $e->id,
                'codigo'    => $e->codigo,
                'titulo'    => $e->titulo,
                'subtitulo' => $e->subtitulo,
                'estado'    => $e->estado,
                'modalidad' => $e->modalidad,
                'periodo_id'=> (int) $e->periodo_id,
                'requiere_inscripcion' => (bool) $e->requiere_inscripcion,
                'cupo_maximo'          => $e->cupo_maximo ? (int) $e->cupo_maximo : null,
                'descripcion_corta'    => $e->descripcion_corta,
                'descripcion_larga'    => $e->descripcion_larga,
                'lugar_detallado'      => $e->lugar_detallado,
                'url_imagen_portada'   => $e->url_imagen_portada,
                'url_enlace_virtual'   => $e->url_enlace_virtual,
                'inscripcion_desde'    => $e->inscripcion_desde,
                'inscripcion_hasta'    => $e->inscripcion_hasta,
                'imagenes'             => $e->imagenes->map(fn ($i) => [
                    'id'    => (int) $i->id,
                    'url'   => $i->url,
                    'path'  => $i->path,
                    'titulo'=> $i->titulo,
                ])->values(),
                'sesiones'             => $e->sesiones->map(fn ($s) => [
                    'id'          => (int) $s->id,
                    'fecha'       => $s->fecha,
                    'hora_inicio' => $s->hora_inicio,
                    'hora_fin'    => $s->hora_fin,
                    'estado'      => $s->estado,
                ])->values(),
                'progreso'             => [
                    'asistidas'  => (int) $asisOk,
                    'totales'    => (int) $tot,
                    'porcentaje' => $tot ? (int) round(($asisOk / $tot) * 100) : 0,
                ],
                'participacion'        => [
                    'estado' => 'INSCRITO', // si quieres, carga el real desde la relación
                ],
            ];
        })->values()->all();
    }

    private function eventosInscribibles(int $expedienteId, int $epSedeId, int $periodoId, Carbon $hoy): array
    {
        $y = $hoy->toDateString();

        $rows = VmEvento::with([
                'sesiones' => fn ($q) => $q->orderBy('fecha')->orderBy('hora_inicio'),
                'imagenes' => fn ($q) => $q->latest(),
            ])
            ->where('periodo_id', $periodoId)
            ->where('targetable_type', 'ep_sede')
            ->where('targetable_id', $epSedeId)
            ->whereIn('estado', ['PLANIFICADO', 'EN_CURSO'])
            ->where('requiere_inscripcion', true)
            ->where(function ($q) use ($y) {
                $q->whereNull('inscripcion_desde')
                  ->orWhereDate('inscripcion_desde', '<=', $y);
            })
            ->where(function ($q) use ($y) {
                $q->whereNull('inscripcion_hasta')
                  ->orWhereDate('inscripcion_hasta', '>=', $y);
            })
            ->whereDoesntHave('participaciones', fn ($q) => $q
                ->where('expediente_id', $expedienteId)
            )
            ->orderByDesc('id')
            ->get();

        return $rows->map(fn ($e) => [
            'id'        => (int) $e->id,
            'codigo'    => $e->codigo,
            'titulo'    => $e->titulo,
            'subtitulo' => $e->subtitulo,
            'estado'    => $e->estado,
            'modalidad' => $e->modalidad,
            'periodo_id'=> (int) $e->periodo_id,
            'requiere_inscripcion' => (bool) $e->requiere_inscripcion,
            'cupo_maximo'          => $e->cupo_maximo ? (int) $e->cupo_maximo : null,
            'descripcion_corta'    => $e->descripcion_corta,
            'descripcion_larga'    => $e->descripcion_larga,
            'lugar_detallado'      => $e->lugar_detallado,
            'url_imagen_portada'   => $e->url_imagen_portada,
            'url_enlace_virtual'   => $e->url_enlace_virtual,
            'inscripcion_desde'    => $e->inscripcion_desde,
            'inscripcion_hasta'    => $e->inscripcion_hasta,
            'imagenes'             => $e->imagenes->map(fn ($i) => [
                'id'    => (int) $i->id,
                'url'   => $i->url,
                'path'  => $i->path,
                'titulo'=> $i->titulo,
            ])->values(),
            'sesiones'             => $e->sesiones->map(fn ($s) => [
                'id'          => (int) $s->id,
                'fecha'       => $s->fecha,
                'hora_inicio' => $s->hora_inicio,
                'hora_fin'    => $s->hora_fin,
                'estado'      => $s->estado,
            ])->values(),
            'ventana'              => [
                'desde' => $e->inscripcion_desde,
                'hasta' => $e->inscripcion_hasta,
            ],
        ])->values()->all();
    }

    private function proyectosInscritos(int $expedienteId, int $epSedeId, int $periodoId): array
    {
        $rows = VmProyecto::with([
                'imagenes'        => fn ($q) => $q->latest(),
                'ciclos',
                'procesos'        => fn ($q) => $q->orderBy('orden')->orderBy('id'),
                'procesos.sesiones'=> fn ($q) => $q->orderBy('fecha')->orderBy('hora_inicio'),
            ])
            ->where('ep_sede_id', $epSedeId)
            ->where('periodo_id', $periodoId)
            ->whereHas('participaciones', fn ($q) => $q
                ->where('expediente_id', $expedienteId)
                ->whereIn('estado', ['INSCRITO', 'CONFIRMADO', 'FINALIZADO'])
            )
            ->orderByDesc('id')
            ->get();

        // minutos validados por proyecto (bulk)
        $projIds   = $rows->pluck('id')->all();
        $minByProj = empty($projIds) ? [] :
            DB::table('vm_asistencias as a')
                ->join('vm_sesiones as s', 's.id', '=', 'a.sesion_id')
                ->join('vm_procesos as p', 'p.id', '=', 's.sessionable_id')
                ->where('a.estado', 'VALIDADO')
                ->where('a.expediente_id', $expedienteId)
                ->where('s.sessionable_type', VmProceso::class)
                ->whereIn('p.proyecto_id', $projIds)
                ->select('p.proyecto_id', DB::raw('COALESCE(SUM(a.minutos_validados),0) as total_min'))
                ->groupBy('p.proyecto_id')
                ->pluck('total_min', 'p.proyecto_id')
                ->toArray();

        return $rows->map(function (VmProyecto $p) use ($minByProj) {
            $reqMin = (int) (($p->horas_minimas_participante ?? $p->horas_planificadas) * 60);
            $accMin = (int) ($minByProj[$p->id] ?? 0);

            return [
                'id'        => (int) $p->id,
                'codigo'    => $p->codigo,
                'titulo'    => $p->titulo,
                'estado'    => $p->estado,
                'tipo'      => $p->tipo,
                'modalidad' => $p->modalidad,
                'periodo_id'=> (int) $p->periodo_id,
                'descripcion'=> $p->descripcion,
                'imagenes'  => $p->imagenes->map(fn ($i) => [
                    'id'    => (int) $i->id,
                    'url'   => $i->url,
                    'path'  => $i->path,
                    'titulo'=> $i->titulo,
                ])->values(),
                'ciclos'   => $p->ciclos->pluck('nivel')->map(fn ($n) => (int) $n)->values(),
                'procesos' => $p->procesos->map(fn ($pr) => [
                    'id'          => (int) $pr->id,
                    'nombre'      => $pr->nombre,
                    'descripcion' => $pr->descripcion,
                    'tipo'        => $pr->tipo_registro,
                    'nota_minima' => $pr->nota_minima,
                    'estado'      => $pr->estado,
                    'sesiones'    => $pr->sesiones->map(fn ($s) => [
                        'id'          => (int) $s->id,
                        'fecha'       => $s->fecha,
                        'hora_inicio' => $s->hora_inicio,
                        'hora_fin'    => $s->hora_fin,
                        'estado'      => $s->estado,
                    ])->values(),
                ])->values(),
                'progreso' => [
                    'min_validados' => (int) $accMin,
                    'min_requeridos'=> $reqMin,
                    'porcentaje'    => $reqMin ? (int) round(($accMin / $reqMin) * 100) : 0,
                ],
                'participacion' => [
                    'estado' => 'INSCRITO', // si necesitas, trae el real
                ],
            ];
        })->values()->all();
    }

    private function proyectosInscribibles(int $expedienteId, int $epSedeId, int $periodoId, Carbon $hoy): array
    {
        // No listar EN_CURSO si no está inscrito: SOLO PLANIFICADO
        $rows = VmProyecto::with([
                'imagenes' => fn ($q) => $q->latest(),
                'ciclos',
            ])
            ->where('ep_sede_id', $epSedeId)
            ->where('periodo_id', $periodoId)
            ->where('estado', 'PLANIFICADO')
            ->whereDoesntHave('participaciones', fn ($q) => $q
                ->where('expediente_id', $expedienteId)
            )
            ->orderByDesc('id')
            ->get();

        return $rows->map(fn ($p) => [
            'id'        => (int) $p->id,
            'codigo'    => $p->codigo,
            'titulo'    => $p->titulo,
            'estado'    => $p->estado,
            'tipo'      => $p->tipo,
            'modalidad' => $p->modalidad,
            'periodo_id'=> (int) $p->periodo_id,
            'descripcion'=> $p->descripcion,
            'imagenes'  => $p->imagenes->map(fn ($i) => [
                'id'    => (int) $i->id,
                'url'   => $i->url,
                'path'  => $i->path,
                'titulo'=> $i->titulo,
            ])->values(),
            'ciclos'   => $p->ciclos->pluck('nivel')->map(fn ($n) => (int) $n)->values(),
            'procesos' => [], // no hace falta árbol hasta que se inscriba
            'progreso' => null,
            'participacion' => null,
        ])->values()->all();
    }
}
