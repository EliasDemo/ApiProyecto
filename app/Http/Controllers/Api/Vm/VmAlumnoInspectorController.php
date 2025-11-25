<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Models\ExpedienteAcademico;
use App\Models\Matricula;
use App\Models\PeriodoAcademico;
use App\Models\VmProyecto;
use App\Models\VmProceso;
use App\Models\VmParticipacion;
use App\Models\VmSesion;
use App\Models\VmAsistencia;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class VmAlumnoInspectorController extends Controller
{
    // ========================= Auth / Scope =========================

    private function requireAuth(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(response()->json(['ok'=>false,'message'=>'No autenticado.'], 401));
        if (!($actor->can('ep.manage.ep_sede') || $actor->can('vm.manage'))) {
            abort(response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403));
        }
        return $actor;
    }

    private function resolverEpSedeIdOrFail($actor, ?int $epSedeId = null): int
    {
        if ($epSedeId) {
            if (!EpScopeService::userManagesEpSede($actor->id, $epSedeId)) {
                abort(response()->json(['ok'=>false,'message'=>'No autorizado para esa EP_SEDE.'], 403));
            }
            return (int)$epSedeId;
        }
        $managed = EpScopeService::epSedesIdsManagedBy($actor->id);
        if (count($managed) === 1) return (int)$managed[0];
        if (count($managed) > 1) {
            abort(response()->json([
                'ok'=>false,
                'message'=>'Administras más de una EP_SEDE. Envía ep_sede_id.',
                'choices'=>$managed
            ], 422));
        }
        abort(response()->json(['ok'=>false,'message'=>'No administras ninguna EP_SEDE activa.'], 403));
    }

    // ========================= Helpers =========================

    private function getPeriodoActual(): ?PeriodoAcademico
    {
        $per = PeriodoAcademico::where('es_actual',1)->first();
        if ($per) return $per;
        return PeriodoAcademico::where('estado','EN_CURSO')
            ->orderByDesc('anio')->orderByDesc('ciclo')
            ->first();
    }

    private function normalizePeriodoCodigo(?string $codigo): ?string
    {
        if (!$codigo) return null;
        $codigo = str_replace('_','-',trim($codigo));
        return strtoupper($codigo);
    }

    private function toIntOrNull($v): ?int
    {
        if ($v===null) return null;
        if (is_numeric($v)) return (int)$v;
        $d = preg_replace('/\D+/','',(string)$v);
        return $d !== '' ? (int)$d : null;
    }

    /**
     * Búsqueda “inteligente” de expediente en una EP-SEDE por código.
     */
    private function resolveExpedienteSmart(int $epSedeId, string $raw): array
    {
        $q = trim($raw);
        if ($q === '') return [null, []];

        $cand = [];
        $cand[] = $q;
        $cand[] = ltrim($q, '0');                 // sin ceros a la izquierda
        $cand[] = preg_replace('/\s+/', '', $q);  // sin espacios

        $cand = array_values(array_unique(array_filter($cand, fn($s) => $s !== '')));

        foreach ($cand as $code) {
            $exp = ExpedienteAcademico::where('ep_sede_id',$epSedeId)
                ->where('codigo_estudiante',$code)
                ->first();
            if ($exp) return [$exp, []];
        }

        return [null, ['tried_variants'=>$cand]];
    }

    // ========================= 1) Resumen por EP-SEDE =========================
    //
    // GET /api/vm/inspeccion/resumen
    // Query:
    //  - ep_sede_id
    //  - periodo_id     (opcional)
    //  - periodo_codigo (opcional; si no hay periodo_id)
    //
    public function resumenEpSede(Request $request)
    {
        $actor    = $this->requireAuth($request);
        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        // Resolver período
        $periodo = null;
        if ($request->filled('periodo_id')) {
            $periodo = PeriodoAcademico::find($request->integer('periodo_id'));
        } elseif ($request->filled('periodo_codigo')) {
            $code    = $this->normalizePeriodoCodigo($request->input('periodo_codigo'));
            $periodo = PeriodoAcademico::where('codigo',$code)->first();
        } else {
            $periodo = $this->getPeriodoActual();
        }

        if (!$periodo) {
            return response()->json([
                'ok'=>false,
                'message'=>'No se pudo resolver el período. Envía periodo_id o periodo_codigo.',
            ], 422);
        }

        $periodoId     = $periodo->id;
        $periodoCodigo = $periodo->codigo;

        // 1) Expedientes en la EP-SEDE
        $expIds = ExpedienteAcademico::where('ep_sede_id',$epSedeId)->pluck('id')->all();
        $totalExpedientes = count($expIds);

        // 2) Matriculados en el período (con fecha_matricula)
        $matriculados = 0;
        if (!empty($expIds)) {
            $matriculados = Matricula::where('periodo_id',$periodoId)
                ->whereIn('expediente_id',$expIds)
                ->whereNotNull('fecha_matricula')
                ->count();
        }
        $noMatriculados = max(0, $totalExpedientes - $matriculados);

        // 3) Expedientes con horas VCM aprobadas en ese período
        $expConHoras = DB::table('registro_horas')
            ->where('ep_sede_id',$epSedeId)
            ->where('periodo_id',$periodoId)
            ->where('estado','APROBADO')
            ->distinct()
            ->pluck('expediente_id')
            ->all();
        $totalConHoras = count($expConHoras);
        $totalSinHoras = max(0, $matriculados - $totalConHoras);

        // 4) Total horas VCM aprobadas en el período
        $totalMinutos = (int) DB::table('registro_horas')
            ->where('ep_sede_id',$epSedeId)
            ->where('periodo_id',$periodoId)
            ->where('estado','APROBADO')
            ->sum('minutos');
        $totalHoras = round($totalMinutos / 60, 2);

        return response()->json([
            'ok' => true,
            'ep_sede_id' => $epSedeId,
            'periodo' => [
                'id'     => $periodoId,
                'codigo' => $periodoCodigo,
                'estado' => $periodo->estado,
            ],
            'stats' => [
                'total_expedientes'           => $totalExpedientes,
                'total_matriculados'          => $matriculados,
                'total_no_matriculados'       => $noMatriculados,
                'total_con_horas_vcm'         => $totalConHoras,
                'total_sin_horas_vcm'         => $totalSinHoras,
                'total_horas_vcm_aprobadas'   => $totalHoras,
                'total_minutos_vcm_aprobados' => $totalMinutos,
            ],
        ]);
    }

    // ========================= 2) Inspección por alumno =========================
    //
    // GET /api/vm/inspeccion/alumno
    // Query:
    //  - ep_sede_id
    //  - expediente_id  (o)
    //  - codigo         (código estudiante)
    //
    // NOTA: no devolvemos nombres/correo; solo info académica/VCM.
    //
    public function inspeccionarAlumno(Request $request)
    {
        $actor    = $this->requireAuth($request);
        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        $expedienteId = $request->integer('expediente_id');
        $codigoRaw    = trim((string)$request->input('codigo',''));

        $exp    = null;
        $nfMeta = [];

        if ($expedienteId) {
            $exp = ExpedienteAcademico::where('ep_sede_id',$epSedeId)
                ->where('id',$expedienteId)
                ->first();
        } elseif ($codigoRaw !== '') {
            [$exp, $nfMeta] = $this->resolveExpedienteSmart($epSedeId, $codigoRaw);
        } else {
            return response()->json([
                'ok'=>false,
                'message'=>'Envía expediente_id o codigo.',
            ], 422);
        }

        if (!$exp) {
            return response()->json([
                'ok'=>false,
                'message'=>'EXPEDIENTE_NO_ENCONTRADO_EN_EP_SEDE',
                'meta'=>$nfMeta,
            ], 404);
        }

        // ----- Matrículas del alumno -----
        $matRows = Matricula::query()
            ->join('periodos_academicos as p','p.id','=','matriculas.periodo_id')
            ->where('matriculas.expediente_id',$exp->id)
            ->orderBy('p.anio')
            ->orderBy('p.ciclo')
            ->get([
                'matriculas.id as matricula_id',
                'matriculas.ciclo as ciclo_matricula',
                'matriculas.grupo',
                'matriculas.modalidad_estudio',
                'matriculas.modo_contrato',
                'matriculas.fecha_matricula',
                'p.id as periodo_id',
                'p.codigo as periodo_codigo',
                'p.anio',
                'p.ciclo as ciclo_periodo',
                'p.estado as periodo_estado',
            ]);

        $matPorPerCodigo = [];
        foreach ($matRows as $m) {
            $pc = $m->periodo_codigo;
            $matPorPerCodigo[$pc] = [
                'periodo_id'      => (int)$m->periodo_id,
                'periodo_codigo'  => $pc,
                'periodo_estado'  => $m->periodo_estado,
                'anio'            => (int)$m->anio,
                'ciclo_periodo'   => (int)$m->ciclo_periodo,
                'ciclo_matricula' => $this->toIntOrNull($m->ciclo_matricula),
                'grupo'           => $m->grupo,
                'modalidad'       => $m->modalidad_estudio,
                'modo_contrato'   => $m->modo_contrato,
                'fecha_matricula' => $m->fecha_matricula,
            ];
        }

        // ----- Horas VCM agrupadas por período y tipo de proyecto -----
        $horasRaw = DB::table('registro_horas as rh')
            ->join('periodos_academicos as p','p.id','=','rh.periodo_id')
            ->leftJoin('vm_procesos as proc', function($join) {
                $join->on('proc.id','=','rh.vinculable_id')
                    ->where('rh.vinculable_type','=', VmProceso::class);
            })
            ->leftJoin('vm_proyectos as proy','proy.id','=','proc.proyecto_id')
            ->where('rh.expediente_id',$exp->id)
            ->where('rh.ep_sede_id',$exp->ep_sede_id)
            ->groupBy('rh.periodo_id','p.codigo','proy.tipo')
            ->selectRaw('rh.periodo_id, p.codigo as periodo_codigo, COALESCE(proy.tipo,"DESCONOCIDO") as tipo_proyecto, SUM(rh.minutos) as minutos')
            ->get();

        $horasPorPerCodigo = [];
        foreach ($horasRaw as $h) {
            $pc = $h->periodo_codigo;
            if (!isset($horasPorPerCodigo[$pc])) {
                $horasPorPerCodigo[$pc] = [
                    'total_minutos'      => 0,
                    'vinculados_minutos' => 0,
                    'libres_minutos'     => 0,
                ];
            }
            $m = (int)$h->minutos;
            $horasPorPerCodigo[$pc]['total_minutos'] += $m;
            if ($h->tipo_proyecto === 'VINCULADO') {
                $horasPorPerCodigo[$pc]['vinculados_minutos'] += $m;
            } elseif ($h->tipo_proyecto === 'LIBRE') {
                $horasPorPerCodigo[$pc]['libres_minutos'] += $m;
            }
        }

        // ----- Proyectos por período + participaciones -----
        $periodoIds = [];
        foreach ($matPorPerCodigo as $pc => $info) {
            $periodoIds[] = $info['periodo_id'];
        }
        foreach ($horasRaw as $h) {
            if (!in_array($h->periodo_id, $periodoIds, true)) {
                $periodoIds[] = $h->periodo_id;
            }
        }

        $proyectos = [];
        if (!empty($periodoIds)) {
            $proyectos = VmProyecto::where('ep_sede_id',$epSedeId)
                ->whereIn('periodo_id',$periodoIds)
                ->get(['id','periodo_id','codigo','titulo','tipo','estado','horas_planificadas']);
        }

        $participaciones = VmParticipacion::where('expediente_id',$exp->id)
            ->where('participable_type', VmProyecto::class)
            ->get(['id','participable_id','estado']);

        $participaPorProyecto = [];
        foreach ($participaciones as $p) {
            $participaPorProyecto[$p->participable_id] = [
                'participacion_id' => $p->id,
                'estado'           => $p->estado,
            ];
        }

        // ----- Construir resumen por período -----
        $resumen = [];

        // base con matrículas
        foreach ($matPorPerCodigo as $pc => $m) {
            $resumen[$pc] = [
                'periodo_codigo'   => $pc,
                'periodo_id'       => $m['periodo_id'],
                'anio'             => $m['anio'],
                'ciclo_periodo'    => $m['ciclo_periodo'],
                'ciclo_matricula'  => $m['ciclo_matricula'],
                'grupo'            => $m['grupo'],
                'periodo_estado'   => $m['periodo_estado'],
                'matriculado'      => !empty($m['fecha_matricula']),
                'fecha_matricula'  => $m['fecha_matricula'],
                // asunción: meta de 5h por período
                'horas_requeridas' => 5,
                'horas_total'      => 0,
                'horas_vinculadas' => 0,
                'horas_libres'     => 0,
                'faltan'           => null,
                'estado_vcm'       => null,
                'proyectos'        => [],
            ];
        }

        // periodos donde solo hay horas (sin matrícula)
        foreach ($horasPorPerCodigo as $pc => $_) {
            if (!isset($resumen[$pc])) {
                // buscar periodo_id en horasRaw:
                $pid = null; $anio = null; $cicloPer = null; $estadoPer = null;
                foreach ($horasRaw as $h) {
                    if ($h->periodo_codigo === $pc) {
                        $pid = $h->periodo_id;
                        $p   = PeriodoAcademico::find($pid);
                        if ($p) {
                            $anio     = (int)$p->anio;
                            $cicloPer = (int)$p->ciclo;
                            $estadoPer= $p->estado;
                        }
                        break;
                    }
                }

                $resumen[$pc] = [
                    'periodo_codigo'   => $pc,
                    'periodo_id'       => $pid,
                    'anio'             => $anio,
                    'ciclo_periodo'    => $cicloPer,
                    'ciclo_matricula'  => null,
                    'grupo'            => null,
                    'periodo_estado'   => $estadoPer,
                    'matriculado'      => false,
                    'fecha_matricula'  => null,
                    'horas_requeridas' => 5,
                    'horas_total'      => 0,
                    'horas_vinculadas' => 0,
                    'horas_libres'     => 0,
                    'faltan'           => null,
                    'estado_vcm'       => null,
                    'proyectos'        => [],
                ];
            }
        }

        // mezclar horas
        foreach ($resumen as $pc => &$row) {
            $hinfo = $horasPorPerCodigo[$pc] ?? null;
            if ($hinfo) {
                $totMin  = (int)$hinfo['total_minutos'];
                $vincMin = (int)$hinfo['vinculados_minutos'];
                $freeMin = (int)$hinfo['libres_minutos'];

                $row['horas_total']      = (int) floor($totMin / 60);
                $row['horas_vinculadas'] = (int) floor($vincMin / 60);
                $row['horas_libres']     = (int) floor($freeMin / 60);
            }

            $row['faltan'] = max(0, $row['horas_requeridas'] - $row['horas_total']);

            if ($row['horas_total'] >= $row['horas_requeridas']) {
                $row['estado_vcm'] = 'COMPLETO';
            } elseif ($row['horas_total'] > 0) {
                $row['estado_vcm'] = 'INCOMPLETO';
            } else {
                $row['estado_vcm'] = 'SIN_HORAS';
            }
        }
        unset($row);

        // adjuntar proyectos
        foreach ($proyectos as $proy) {
            // localizar su periodo_codigo
            $pc = null;
            foreach ($resumen as $key => $row) {
                if ($row['periodo_id'] === $proy->periodo_id) {
                    $pc = $key; break;
                }
            }
            if ($pc === null) continue;

            $participa = $participaPorProyecto[$proy->id] ?? null;

            $resumen[$pc]['proyectos'][] = [
                'proyecto_id'          => $proy->id,
                'codigo'               => $proy->codigo,
                'titulo'               => $proy->titulo,
                'tipo'                 => $proy->tipo,
                'estado'               => $proy->estado,
                'horas_planificadas'   => $proy->horas_planificadas,
                'participa'            => (bool)$participa,
                'participacion_id'     => $participa['participacion_id'] ?? null,
                'estado_participacion' => $participa['estado'] ?? null,
            ];
        }

        ksort($resumen);

        return response()->json([
            'ok'                => true,
            'ep_sede_id'        => $epSedeId,
            'expediente_id'     => $exp->id,
            'codigo_estudiante' => $exp->codigo_estudiante,
            // no devolvemos nombre/correo
            'resumen'           => array_values($resumen),
        ]);
    }

    // ========================= 3) Proyectos por período + nivel =========================
    //
    // GET /api/vm/inspeccion/proyectos
    // Query:
    //  - ep_sede_id
    //  - periodo_codigo (YYYY-1/2)
    //  - nivel (ciclo)  (opcional; si no, se devuelven todos)
    //
    public function proyectosPeriodoNivel(Request $request)
    {
        $actor    = $this->requireAuth($request);
        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        $codigo = $this->normalizePeriodoCodigo($request->input('periodo_codigo'));
        if (!$codigo) {
            return response()->json([
                'ok'=>false,
                'message'=>'Debes enviar periodo_codigo.',
            ], 422);
        }

        $periodo = PeriodoAcademico::where('codigo',$codigo)->first();
        if (!$periodo) {
            return response()->json([
                'ok'=>false,
                'message'=>'PERIODO_NO_EXISTE',
            ], 404);
        }

        $nivel = $request->has('nivel') ? (int)$request->input('nivel') : null;
        $nivelCode = $nivel ? sprintf('N%02d', max(1, min(10, $nivel))) : null;

        $qBase = VmProyecto::where('ep_sede_id',$epSedeId)
            ->where('periodo_id',$periodo->id);

        $vincQ = (clone $qBase)->where('tipo','VINCULADO');
        $libQ  = (clone $qBase)->where('tipo','LIBRE');

        if ($nivelCode) {
            // para históricos: HIST-xxxx-N05..., HIST-LIBRE-xxxx-N05...
            $vincQ->where(function($q) use ($nivelCode) {
                $q->where('codigo','like','%'.$nivelCode.'%')
                  ->orWhereNull('codigo');
            });
            $libQ->where(function($q) use ($nivelCode) {
                $q->where('codigo','like','%'.$nivelCode.'%')
                  ->orWhereNull('codigo');
            });
        }

        $vinculados = $vincQ->orderBy('codigo')->get(['id','codigo','titulo','horas_planificadas']);
        $libres     = $libQ->orderBy('codigo')->get(['id','codigo','titulo','horas_planificadas']);

        return response()->json([
            'ok' => true,
            'ep_sede_id' => $epSedeId,
            'periodo' => [
                'id'     => $periodo->id,
                'codigo' => $periodo->codigo,
            ],
            'nivel' => $nivel,
            'vinculados' => $vinculados,
            'libres'     => $libres,
        ]);
    }

    // ========================= 4) Inscribir alumno en proyecto =========================
    //
    // POST /api/vm/inspeccion/inscribir
    //
    // Body:
    //  - ep_sede_id
    //  - expediente_id (o codigo)
    //  - proyecto_id
    //
    public function inscribirEnProyecto(Request $request)
    {
        $actor = $this->requireAuth($request);

        $v = Validator::make($request->all(), [
            'ep_sede_id'    => ['nullable','integer','exists:ep_sede,id'],
            'expediente_id' => ['nullable','integer'],
            'codigo'        => ['nullable','string'],
            'proyecto_id'   => ['required','integer','exists:vm_proyectos,id'],
        ]);

        if ($v->fails()) {
            return response()->json(['ok'=>false,'errors'=>$v->errors()], 422);
        }

        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        $proyecto = VmProyecto::find($request->integer('proyecto_id'));
        if (!$proyecto || $proyecto->ep_sede_id !== $epSedeId) {
            return response()->json([
                'ok'=>false,
                'message'=>'PROYECTO_NO_PERTENECE_A_EP_SEDE',
            ], 422);
        }

        // Resolver expediente
        $exp = null; $metaNF = [];
        if ($request->filled('expediente_id')) {
            $exp = ExpedienteAcademico::where('ep_sede_id',$epSedeId)
                ->where('id',$request->integer('expediente_id'))
                ->first();
        } else {
            $codigo = (string)$request->input('codigo','');
            [$exp, $metaNF] = $this->resolveExpedienteSmart($epSedeId, $codigo);
        }
        if (!$exp) {
            return response()->json([
                'ok'=>false,
                'message'=>'EXPEDIENTE_NO_ENCONTRADO_EN_EP_SEDE',
                'meta'=>$metaNF,
            ], 404);
        }

        $part = VmParticipacion::firstOrCreate(
            [
                'participable_type' => VmProyecto::class,
                'participable_id'   => $proyecto->id,
                'expediente_id'     => $exp->id,
            ],
            [
                'rol'    => 'ALUMNO',
                'estado' => 'INSCRITO',
            ]
        );

        return response()->json([
            'ok' => true,
            'expediente_id' => $exp->id,
            'codigo_estudiante' => $exp->codigo_estudiante,
            'proyecto_id' => $proyecto->id,
            'participacion' => [
                'id'     => $part->id,
                'estado' => $part->estado,
                'rol'    => $part->rol,
            ],
        ]);
    }

    // ========================= 5) Marcar asistencias manualmente =========================
    //
    // POST /api/vm/inspeccion/asistencias/marcar
    //
    // Body:
    //  - ep_sede_id
    //  - expediente_id (o codigo)
    //  - sesion_ids: [int, int, ...]
    //
    // Para cada sesión:
    //  - se verifica que el proyecto pertenezca a la EP-SEDE
    //  - se asegura VmParticipacion (al proyecto)
    //  - se crea/actualiza VmAsistencia como VALIDADO
    //  - se crea/actualiza registro_horas con meta.source = 'manual_inspeccion'
    //
    public function marcarAsistencias(Request $request)
    {
        $actor = $this->requireAuth($request);

        $v = Validator::make($request->all(), [
            'ep_sede_id'    => ['nullable','integer','exists:ep_sede,id'],
            'expediente_id' => ['nullable','integer'],
            'codigo'        => ['nullable','string'],
            'sesion_ids'    => ['required','array','min:1'],
            'sesion_ids.*'  => ['integer','distinct'],
        ], [], [
            'sesion_ids' => 'sesiones',
        ]);

        if ($v->fails()) {
            return response()->json(['ok'=>false,'errors'=>$v->errors()], 422);
        }

        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        // Resolver expediente
        $exp = null; $metaNF = [];
        if ($request->filled('expediente_id')) {
            $exp = ExpedienteAcademico::where('ep_sede_id',$epSedeId)
                ->where('id',$request->integer('expediente_id'))
                ->first();
        } else {
            $codigo = (string)$request->input('codigo','');
            [$exp, $metaNF] = $this->resolveExpedienteSmart($epSedeId, $codigo);
        }
        if (!$exp) {
            return response()->json([
                'ok'=>false,
                'message'=>'EXPEDIENTE_NO_ENCONTRADO_EN_EP_SEDE',
                'meta'=>$metaNF,
            ], 404);
        }

        $sesionIds = $request->input('sesion_ids', []);
        $sesiones  = VmSesion::whereIn('id',$sesionIds)->get();

        if ($sesiones->count() === 0) {
            return response()->json([
                'ok'=>false,
                'message'=>'NO_SE_ENCONTRARON_SESIONES',
            ], 404);
        }

        $hechas = [];
        foreach ($sesiones as $ses) {
            /** @var VmSesion $ses */
            $proc = $ses->proceso;
            if (!$proc) continue;
            $proy = $proc->proyecto;
            if (!$proy) continue;

            if ($proy->ep_sede_id !== $epSedeId) {
                // saltamos sesiones de otros EP-SEDE
                continue;
            }

            // asegurar participación en el proyecto
            VmParticipacion::firstOrCreate(
                [
                    'participable_type' => VmProyecto::class,
                    'participable_id'   => $proy->id,
                    'expediente_id'     => $exp->id,
                ],
                [
                    'rol'    => 'ALUMNO',
                    'estado' => 'INSCRITO',
                ]
            );

            // calcular minutos según duración real de la sesión
            $fecha = Carbon::parse((string)$ses->fecha)->toDateString();
            $hiStr = (string)$ses->hora_inicio;
            $hfStr = (string)$ses->hora_fin;

            if (strlen($hiStr) === 5) $hiStr .= ':00';
            if (strlen($hfStr) === 5) $hfStr .= ':00';

            $checkIn  = Carbon::parse($fecha.' '.$hiStr);
            $checkOut = Carbon::parse($fecha.' '.$hfStr);

            $minutos = max(0, $checkIn->diffInMinutes($checkOut));

            // upsert asistencia
            $asis = VmAsistencia::updateOrCreate(
                [
                    'sesion_id'    => $ses->id,
                    'expediente_id'=> $exp->id,
                ],
                [
                    'metodo'            => 'MANUAL_INSPECCION',
                    'estado'            => 'VALIDADO',
                    'check_in_at'       => $checkIn,
                    'check_out_at'      => $checkOut,
                    'minutos_validados' => $minutos,
                    'meta'              => [
                        'source'  => 'manual_inspeccion',
                        'proyecto'=> $proy->codigo,
                        'periodo' => $proy->periodo->codigo ?? null,
                    ],
                ]
            );

            // upsert registro_horas
            $periodoId     = $proy->periodo_id;
            $periodoCodigo = $proy->periodo->codigo ?? null;

            DB::table('registro_horas')->updateOrInsert(
                [
                    'asistencia_id' => $asis->id,
                ],
                [
                    'expediente_id'   => $exp->id,
                    'ep_sede_id'      => $epSedeId,
                    'periodo_id'      => $periodoId,
                    'fecha'           => $fecha,
                    'minutos'         => $minutos,
                    'actividad'       => 'Validación manual VCM (inspección)',
                    'estado'          => 'APROBADO',
                    'vinculable_type' => VmProceso::class,
                    'vinculable_id'   => $proc->id,
                    'sesion_id'       => $ses->id,
                    'updated_at'      => now(),
                    'created_at'      => now(),
                ]
            );

            $hechas[] = [
                'sesion_id'     => $ses->id,
                'proyecto_id'   => $proy->id,
                'proyecto_cod'  => $proy->codigo,
                'periodo_codigo'=> $periodoCodigo,
                'minutos'       => $minutos,
                'asistencia_id' => $asis->id,
            ];
        }

        return response()->json([
            'ok'                => true,
            'expediente_id'     => $exp->id,
            'codigo_estudiante' => $exp->codigo_estudiante,
            'registros_creados' => $hechas,
        ]);
    }
}
