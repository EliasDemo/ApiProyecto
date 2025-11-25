<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Models\ExpedienteAcademico;
use App\Models\Matricula;
use App\Models\PeriodoAcademico;
use App\Models\VmProyecto;
use App\Models\VmProceso;
use App\Models\VmSesion;
use App\Models\VmAsistencia;
use App\Models\VmEvento;
use App\Models\VmParticipacion;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\JsonResponse;
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
        if (!$actor) {
            abort(response()->json(['ok' => false, 'message' => 'No autenticado.'], 401));
        }
        if (!($actor->can('ep.manage.ep_sede') || $actor->can('vm.manage'))) {
            abort(response()->json(['ok' => false, 'message' => 'NO_AUTORIZADO'], 403));
        }
        return $actor;
    }

    private function resolverEpSedeIdOrFail($actor, ?int $epSedeId = null): int
    {
        if ($epSedeId) {
            if (!EpScopeService::userManagesEpSede($actor->id, $epSedeId)) {
                abort(response()->json(['ok' => false, 'message' => 'No autorizado para esa EP_SEDE.'], 403));
            }
            return (int) $epSedeId;
        }

        $managed = EpScopeService::epSedesIdsManagedBy($actor->id);
        if (count($managed) === 1) {
            return (int) $managed[0];
        }
        if (count($managed) > 1) {
            abort(response()->json([
                'ok'      => false,
                'message' => 'Administras más de una EP_SEDE. Envía ep_sede_id.',
                'choices' => $managed,
            ], 422));
        }
        abort(response()->json(['ok' => false, 'message' => 'No administras ninguna EP_SEDE activa.'], 403));
    }

    // ========================= Helpers comunes =========================

    private function getPeriodoActual(): ?PeriodoAcademico
    {
        $per = PeriodoAcademico::where('es_actual', 1)->first();
        if ($per) return $per;

        return PeriodoAcademico::where('estado', 'EN_CURSO')
            ->orderByDesc('anio')
            ->orderByDesc('ciclo')
            ->first();
    }

    private function normalizePeriodoCodigo(?string $codigo): ?string
    {
        if (!$codigo) return null;
        $codigo = str_replace('_', '-', trim($codigo));
        return strtoupper($codigo);
    }

    private function toIntOrNull($v): ?int
    {
        if ($v === null) return null;
        if (is_numeric($v)) return (int) $v;
        $d = preg_replace('/\D+/', '', (string) $v);
        return $d !== '' ? (int) $d : null;
    }

    /**
     * Búsqueda “inteligente” de expediente por código dentro de una EP_SEDE.
     */
    private function resolveExpedienteSmart(int $epSedeId, string $raw): array
    {
        $q = trim($raw);
        if ($q === '') return [null, []];

        $cand = [];
        $cand[] = $q;
        $cand[] = ltrim($q, '0');                 // sin ceros a la izquierda
        $cand[] = preg_replace('/\s+/', '', $q);  // sin espacios internos

        $cand = array_values(array_unique(array_filter($cand, fn ($s) => $s !== '')));

        foreach ($cand as $code) {
            $exp = ExpedienteAcademico::where('ep_sede_id', $epSedeId)
                ->where('codigo_estudiante', $code)
                ->first();
            if ($exp) return [$exp, []];
        }

        return [null, ['tried_variants' => $cand]];
    }

    private function epSedeIdFromEvento(VmEvento $evento): ?int
    {
        if ($evento->targetable_type === 'ep_sede' && $evento->targetable_id) {
            return (int) $evento->targetable_id;
        }
        return null;
    }

    private function fail(string $code, string $message, int $status = 422, array $meta = []): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'code'    => $code,
            'message' => $message,
            'meta'    => (object) $meta,
        ], $status);
    }

    // ========================= 1) Resumen por EP-SEDE =========================
    //
    // GET /api/vm/inspeccion/resumen
    //
    // Query:
    //  - ep_sede_id
    //  - periodo_id     (opcional)
    //  - periodo_codigo (opcional)
    //
    public function resumenEpSede(Request $request): JsonResponse
    {
        $actor    = $this->requireAuth($request);
        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        // Resolver período
        $periodo = null;
        if ($request->filled('periodo_id')) {
            $periodo = PeriodoAcademico::find($request->integer('periodo_id'));
        } elseif ($request->filled('periodo_codigo')) {
            $code    = $this->normalizePeriodoCodigo($request->input('periodo_codigo'));
            $periodo = PeriodoAcademico::where('codigo', $code)->first();
        } else {
            $periodo = $this->getPeriodoActual();
        }

        if (!$periodo) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo resolver el período. Envía periodo_id o periodo_codigo.',
            ], 422);
        }

        $periodoId     = $periodo->id;
        $periodoCodigo = $periodo->codigo;

        // Expedientes en esa EP-SEDE
        $expIds = ExpedienteAcademico::where('ep_sede_id', $epSedeId)->pluck('id')->all();
        $totalExpedientes = count($expIds);

        // Matriculados en ese periodo
        $matriculados = 0;
        if (!empty($expIds)) {
            $matriculados = Matricula::where('periodo_id', $periodoId)
                ->whereIn('expediente_id', $expIds)
                ->whereNotNull('fecha_matricula')
                ->count();
        }
        $noMatriculados = max(0, $totalExpedientes - $matriculados);

        // Estudiantes con horas VCM aprobadas en ese período
        $expConHoras = DB::table('registro_horas')
            ->where('ep_sede_id', $epSedeId)
            ->where('periodo_id', $periodoId)
            ->where('estado', 'APROBADO')
            ->distinct()
            ->pluck('expediente_id')
            ->all();

        $totalConHoras = count($expConHoras);
        $totalSinHoras = max(0, $matriculados - $totalConHoras);

        // Horas totales en el período
        $totalMinutos = (int) DB::table('registro_horas')
            ->where('ep_sede_id', $epSedeId)
            ->where('periodo_id', $periodoId)
            ->where('estado', 'APROBADO')
            ->sum('minutos');

        $totalHoras = round($totalMinutos / 60, 2);

        // Eventos del período en esa EP-SEDE
        $eventosIds = VmEvento::where('periodo_id', $periodoId)
            ->where('targetable_type', 'ep_sede')
            ->where('targetable_id', $epSedeId)
            ->pluck('id')
            ->all();

        $totalEventos = count($eventosIds);

        $participacionesEventos = 0;
        $alumnosConEventos      = 0;

        if (!empty($eventosIds)) {
            $participacionesEventos = VmParticipacion::where('participable_type', VmEvento::class)
                ->whereIn('participable_id', $eventosIds)
                ->count();

            $alumnosConEventos = VmParticipacion::where('participable_type', VmEvento::class)
                ->whereIn('participable_id', $eventosIds)
                ->distinct()
                ->count('expediente_id');
        }

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
                'total_eventos'               => $totalEventos,
                'participaciones_eventos'     => $participacionesEventos,
                'alumnos_con_eventos'         => $alumnosConEventos,
            ],
        ], 200);
    }

    // ========================= 2) Inspección de un alumno =========================
    //
    // GET /api/vm/inspeccion/alumno
    //
    // Query:
    //  - ep_sede_id
    //  - expediente_id  (opcional)
    //  - codigo         (opcional; código estudiante)
    //
    public function inspeccionarAlumno(Request $request): JsonResponse
    {
        $actor    = $this->requireAuth($request);
        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        $expedienteId = $request->integer('expediente_id');
        $codigoRaw    = trim((string) $request->input('codigo', ''));

        $exp    = null;
        $nfMeta = [];

        if ($expedienteId) {
            $exp = ExpedienteAcademico::where('ep_sede_id', $epSedeId)
                ->where('id', $expedienteId)
                ->first();
        } elseif ($codigoRaw !== '') {
            [$exp, $nfMeta] = $this->resolveExpedienteSmart($epSedeId, $codigoRaw);
        } else {
            return response()->json([
                'ok'      => false,
                'message' => 'Debes enviar expediente_id o codigo.',
            ], 422);
        }

        if (!$exp) {
            return response()->json([
                'ok'      => false,
                'message' => 'EXPEDIENTE_NO_ENCONTRADO_EN_EP_SEDE',
                'meta'    => $nfMeta,
            ], 404);
        }

        $user = optional($exp->user);
        $alumno = [
            'expediente' => [
                'id'                => (int) $exp->id,
                'codigo_estudiante' => $exp->codigo_estudiante,
                'ep_sede_id'        => (int) $exp->ep_sede_id,
                'estado'            => $exp->estado,
                'ciclo'             => $exp->ciclo,
                'grupo'             => $exp->grupo,
                'correo_institucional' => $exp->correo_institucional,
            ],
            'usuario' => [
                'id'         => $user->id ? (int) $user->id : null,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'full_name'  => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: null,
                'email'      => $user->email,
                'celular'    => $user->celular,
            ],
        ];

        // -------- Matrículas del alumno (histórico) --------
        $matRows = Matricula::query()
            ->join('periodos_academicos as p', 'p.id', '=', 'matriculas.periodo_id')
            ->where('matriculas.expediente_id', $exp->id)
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

        $matriculas = $matRows->map(function ($m) {
            return [
                'matricula_id'   => (int) $m->matricula_id,
                'periodo_id'     => (int) $m->periodo_id,
                'periodo_codigo' => $m->periodo_codigo,
                'anio'           => (int) $m->anio,
                'ciclo_periodo'  => (int) $m->ciclo_periodo,
                'periodo_estado' => $m->periodo_estado,
                'ciclo_matricula'=> $m->ciclo_matricula,
                'grupo'          => $m->grupo,
                'modalidad'      => $m->modalidad_estudio,
                'modo_contrato'  => $m->modo_contrato,
                'fecha_matricula'=> $m->fecha_matricula,
            ];
        })->values();

        // -------- Horas VCM por período y proyecto --------
        $horasRows = DB::table('registro_horas as rh')
            ->join('periodos_academicos as p', 'p.id', '=', 'rh.periodo_id')
            ->leftJoin('vm_procesos as proc', function ($join) {
                $join->on('proc.id', '=', 'rh.vinculable_id')
                    ->where('rh.vinculable_type', '=', VmProceso::class);
            })
            ->leftJoin('vm_proyectos as proy', 'proy.id', '=', 'proc.proyecto_id')
            ->where('rh.expediente_id', $exp->id)
            ->where('rh.ep_sede_id', $exp->ep_sede_id)
            ->groupBy(
                'rh.periodo_id',
                'p.codigo',
                'p.anio',
                'p.ciclo',
                'p.estado',
                'proy.id',
                'proy.codigo',
                'proy.titulo',
                'proy.tipo'
            )
            ->orderBy('p.anio')
            ->orderBy('p.ciclo')
            ->get([
                'rh.periodo_id',
                'p.codigo as periodo_codigo',
                'p.anio',
                'p.ciclo as periodo_ciclo',
                'p.estado as periodo_estado',
                DB::raw('SUM(rh.minutos) as minutos'),
                'proy.id as proyecto_id',
                'proy.codigo as proyecto_codigo',
                'proy.titulo as proyecto_titulo',
                'proy.tipo as proyecto_tipo',
            ]);

        $vcmPorPeriodo = [];
        foreach ($horasRows as $row) {
            $pid = (int) $row->periodo_id;
            if (!isset($vcmPorPeriodo[$pid])) {
                $vcmPorPeriodo[$pid] = [
                    'periodo_id'     => $pid,
                    'periodo_codigo' => $row->periodo_codigo,
                    'anio'           => (int) $row->anio,
                    'ciclo_periodo'  => (int) $row->periodo_ciclo,
                    'periodo_estado' => $row->periodo_estado,
                    'total_minutos'  => 0,
                    'proyectos'      => [],
                ];
            }

            $min = (int) $row->minutos;
            $vcmPorPeriodo[$pid]['total_minutos'] += $min;

            $vcmPorPeriodo[$pid]['proyectos'][] = [
                'proyecto_id'     => $row->proyecto_id ? (int) $row->proyecto_id : null,
                'codigo'          => $row->proyecto_codigo,
                'titulo'          => $row->proyecto_titulo,
                'tipo'            => $row->proyecto_tipo,
                'horas'           => round($min / 60, 2),
            ];
        }

        foreach ($vcmPorPeriodo as &$info) {
            $info['total_horas'] = round($info['total_minutos'] / 60, 2);
        }
        unset($info);

        // -------- Eventos del alumno --------
        $eventRows = DB::table('vm_participaciones as vp')
            ->join('vm_eventos as ve', 've.id', '=', 'vp.participable_id')
            ->join('periodos_academicos as pa', 'pa.id', '=', 've.periodo_id')
            ->where('vp.participable_type', VmEvento::class)
            ->where('vp.expediente_id', $exp->id)
            ->select([
                'vp.id as participacion_id',
                'vp.estado as participacion_estado',
                'vp.rol as participacion_rol',

                've.id as evento_id',
                've.codigo',
                've.titulo',
                've.subtitulo',
                've.modalidad',
                've.estado as evento_estado',
                've.periodo_id',
                've.requiere_inscripcion',
                've.cupo_maximo',
                've.descripcion_corta',
                've.descripcion_larga',
                've.lugar_detallado',
                've.url_imagen_portada',
                've.url_enlace_virtual',
                've.inscripcion_desde',
                've.inscripcion_hasta',

                'pa.anio as periodo_anio',
                'pa.ciclo as periodo_ciclo',
                'pa.codigo as periodo_codigo',
                'pa.estado as periodo_estado',
            ])
            ->orderByDesc('pa.anio')
            ->orderByDesc('pa.ciclo')
            ->orderBy('ve.id')
            ->get();

        $eventos = $eventRows->map(function ($r) {
            return [
                'id'          => (int) $r->evento_id,
                'codigo'      => $r->codigo,
                'titulo'      => $r->titulo,
                'subtitulo'   => $r->subtitulo,
                'modalidad'   => $r->modalidad,
                'estado'      => $r->evento_estado,
                'periodo_id'  => (int) $r->periodo_id,
                'periodo'     => [
                    'codigo' => $r->periodo_codigo,
                    'anio'   => (int) $r->periodo_anio,
                    'ciclo'  => (int) $r->periodo_ciclo,
                    'estado' => $r->periodo_estado,
                ],
                'requiere_inscripcion' => (bool) $r->requiere_inscripcion,
                'cupo_maximo'          => $r->cupo_maximo ? (int) $r->cupo_maximo : null,
                'descripcion_corta'    => $r->descripcion_corta,
                'descripcion_larga'    => $r->descripcion_larga,
                'lugar_detallado'      => $r->lugar_detallado,
                'url_imagen_portada'   => $r->url_imagen_portada,
                'url_enlace_virtual'   => $r->url_enlace_virtual,
                'inscripcion_desde'    => $r->inscripcion_desde,
                'inscripcion_hasta'    => $r->inscripcion_hasta,
                'participacion' => [
                    'id'     => (int) $r->participacion_id,
                    'estado' => $r->participacion_estado,
                    'rol'    => $r->participacion_rol,
                ],
            ];
        })->values();

        // -------- Response --------
        return response()->json([
            'ok'      => true,
            'ep_sede_id' => $epSedeId,
            'alumno'  => $alumno,
            'matriculas' => $matriculas,
            'vcm' => array_values($vcmPorPeriodo),
            'eventos' => $eventos,
        ], 200);
    }

    // ========================= 3) Proyectos por período y nivel =========================
    //
    // GET /api/vm/inspeccion/proyectos
    //
    // Query:
    //  - ep_sede_id
    //  - periodo_codigo (YYYY-1/2)
    //  - nivel (ciclo)  (opcional)
    //
    public function proyectosPeriodoNivel(Request $request): JsonResponse
    {
        $actor    = $this->requireAuth($request);
        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        $codigo = $this->normalizePeriodoCodigo($request->input('periodo_codigo'));
        if (!$codigo) {
            return response()->json([
                'ok'      => false,
                'message' => 'Debes enviar periodo_codigo.',
            ], 422);
        }

        $periodo = PeriodoAcademico::where('codigo', $codigo)->first();
        if (!$periodo) {
            return response()->json([
                'ok'      => false,
                'message' => 'PERIODO_NO_EXISTE',
            ], 404);
        }

        $nivel = $request->has('nivel') ? (int) $request->input('nivel') : null;
        $nivelCode = $nivel ? sprintf('N%02d', max(1, min(10, $nivel))) : null;

        $qBase = VmProyecto::where('ep_sede_id', $epSedeId)
            ->where('periodo_id', $periodo->id);

        $vincQ = (clone $qBase)->where('tipo', 'VINCULADO');
        $libQ  = (clone $qBase)->where('tipo', 'LIBRE');

        if ($nivelCode) {
            $vincQ->where(function ($q) use ($nivelCode) {
                $q->where('codigo', 'like', '%' . $nivelCode . '%')
                  ->orWhereNull('codigo');
            });
            $libQ->where(function ($q) use ($nivelCode) {
                $q->where('codigo', 'like', '%' . $nivelCode . '%')
                  ->orWhereNull('codigo');
            });
        }

        $vinculados = $vincQ->orderBy('codigo')->get(['id', 'codigo', 'titulo', 'horas_planificadas', 'tipo', 'estado']);
        $libres     = $libQ->orderBy('codigo')->get(['id', 'codigo', 'titulo', 'horas_planificadas', 'tipo', 'estado']);

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
        ], 200);
    }

    // ========================= 4) Inscribir en proyecto (staff) =========================
    //
    // POST /api/vm/inspeccion/proyectos/inscribir
    //
    // Body JSON:
    //  - ep_sede_id
    //  - expediente_id (o codigo)
    //  - proyecto_id
    //
    public function inscribirEnProyecto(Request $request): JsonResponse
    {
        $actor = $this->requireAuth($request);

        $v = Validator::make($request->all(), [
            'ep_sede_id'    => ['nullable', 'integer', 'exists:ep_sede,id'],
            'expediente_id' => ['nullable', 'integer'],
            'codigo'        => ['nullable', 'string'],
            'proyecto_id'   => ['required', 'integer', 'exists:vm_proyectos,id'],
        ]);

        if ($v->fails()) {
            return response()->json(['ok' => false, 'errors' => $v->errors()], 422);
        }

        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        $proyecto = VmProyecto::find($request->integer('proyecto_id'));
        if (!$proyecto || $proyecto->ep_sede_id !== $epSedeId) {
            return response()->json([
                'ok'      => false,
                'message' => 'PROYECTO_NO_PERTENECE_A_EP_SEDE',
            ], 422);
        }

        // Resolver expediente
        $exp = null; $metaNF = [];
        if ($request->filled('expediente_id')) {
            $exp = ExpedienteAcademico::where('ep_sede_id', $epSedeId)
                ->where('id', $request->integer('expediente_id'))
                ->first();
        } else {
            $codigo = (string) $request->input('codigo', '');
            [$exp, $metaNF] = $this->resolveExpedienteSmart($epSedeId, $codigo);
        }

        if (!$exp) {
            return response()->json([
                'ok'      => false,
                'message' => 'EXPEDIENTE_NO_ENCONTRADO_EN_EP_SEDE',
                'meta'    => $metaNF,
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
            'data' => [
                'expediente_id'     => $exp->id,
                'codigo_estudiante' => $exp->codigo_estudiante,
                'proyecto_id'       => $proyecto->id,
                'participacion'     => $part,
            ],
        ], 200);
    }

    // ========================= 5) Marcar asistencias manuales en proyecto =========================
    //
    // POST /api/vm/inspeccion/proyectos/asistencias/marcar
    //
    // Body:
    //  - ep_sede_id
    //  - expediente_id (o codigo)
    //  - sesion_ids: [id, ...]
    //
    public function marcarAsistenciasProyecto(Request $request): JsonResponse
    {
        $actor = $this->requireAuth($request);

        $v = Validator::make($request->all(), [
            'ep_sede_id'    => ['nullable', 'integer', 'exists:ep_sede,id'],
            'expediente_id' => ['nullable', 'integer'],
            'codigo'        => ['nullable', 'string'],
            'sesion_ids'    => ['required', 'array', 'min:1'],
            'sesion_ids.*'  => ['integer', 'distinct'],
        ], [], [
            'sesion_ids' => 'sesiones',
        ]);

        if ($v->fails()) {
            return response()->json(['ok' => false, 'errors' => $v->errors()], 422);
        }

        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));

        // Resolver expediente
        $exp = null; $metaNF = [];
        if ($request->filled('expediente_id')) {
            $exp = ExpedienteAcademico::where('ep_sede_id', $epSedeId)
                ->where('id', $request->integer('expediente_id'))
                ->first();
        } else {
            $codigo = (string) $request->input('codigo', '');
            [$exp, $metaNF] = $this->resolveExpedienteSmart($epSedeId, $codigo);
        }

        if (!$exp) {
            return response()->json([
                'ok'      => false,
                'message' => 'EXPEDIENTE_NO_ENCONTRADO_EN_EP_SEDE',
                'meta'    => $metaNF,
            ], 404);
        }

        $sesionIds = $request->input('sesion_ids', []);
        $sesiones  = VmSesion::whereIn('id', $sesionIds)->get();

        if ($sesiones->count() === 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'NO_SE_ENCONTRARON_SESIONES',
            ], 404);
        }

        $resultados = [];

        foreach ($sesiones as $ses) {
            /** @var VmSesion $ses */
            $proc = $ses->proceso;
            if (!$proc) continue;

            $proy = $proc->proyecto;
            if (!$proy) continue;

            if ((int) $proy->ep_sede_id !== $epSedeId) {
                // saltar sesiones de otros EP-SEDE
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

            // calcular duración
            $fecha = Carbon::parse((string) $ses->fecha)->toDateString();
            $hiStr = (string) $ses->hora_inicio;
            $hfStr = (string) $ses->hora_fin;

            if (strlen($hiStr) === 5) $hiStr .= ':00';
            if (strlen($hfStr) === 5) $hfStr .= ':00';

            $checkIn  = Carbon::parse($fecha . ' ' . $hiStr);
            $checkOut = Carbon::parse($fecha . ' ' . $hfStr);
            $minutos  = max(0, $checkIn->diffInMinutes($checkOut));

            // upsert asistencia
            $asis = VmAsistencia::updateOrCreate(
                [
                    'sesion_id'     => $ses->id,
                    'expediente_id' => $exp->id,
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
                        'periodo' => $proy->periodo_id ? optional(PeriodoAcademico::find($proy->periodo_id))->codigo : null,
                    ],
                ]
            );

            // upsert registro_horas
            $periodoId = $proy->periodo_id;
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

            $resultados[] = [
                'sesion_id'     => $ses->id,
                'proyecto_id'   => $proy->id,
                'proyecto_cod'  => $proy->codigo,
                'minutos'       => $minutos,
                'asistencia_id' => $asis->id,
            ];
        }

        return response()->json([
            'ok'                => true,
            'expediente_id'     => $exp->id,
            'codigo_estudiante' => $exp->codigo_estudiante,
            'registros'         => $resultados,
        ], 200);
    }

    // ========================= 6) Inscribir alumno en evento (staff) =========================
    //
    // POST /api/vm/inspeccion/eventos/{evento}/inscribir
    //
    // Body:
    //  - ep_sede_id (opcional; se valida contra el evento)
    //  - expediente_id (o codigo)
    //
    public function inscribirEnEventoManual(Request $request, VmEvento $evento): JsonResponse
    {
        $actor = $this->requireAuth($request);

        // Verificar EP-SEDE del evento y permisos del actor
        $eventoEpSedeId = $this->epSedeIdFromEvento($evento);
        if (!$eventoEpSedeId) {
            return $this->fail('EVENT_WITHOUT_EP_SEDE', 'Este evento no está asociado a una EP-SEDE.');
        }

        if (!EpScopeService::userManagesEpSede($actor->id, $eventoEpSedeId)) {
            return $this->fail('NOT_AUTHORIZED_FOR_EP_SEDE', 'No autorizado para esta EP-SEDE.', 403);
        }

        $v = Validator::make($request->all(), [
            'ep_sede_id'    => ['nullable', 'integer', 'exists:ep_sede,id'],
            'expediente_id' => ['nullable', 'integer'],
            'codigo'        => ['nullable', 'string'],
        ]);

        if ($v->fails()) {
            return response()->json(['ok' => false, 'errors' => $v->errors()], 422);
        }

        // Resolver EP-SEDE (debe coincidir con la del evento)
        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->integer('ep_sede_id'));
        if ($epSedeId !== $eventoEpSedeId) {
            return $this->fail(
                'EP_SEDE_MISMATCH',
                'La EP-SEDE indicada no coincide con la del evento.',
                422,
                ['evento_ep_sede_id' => $eventoEpSedeId, 'ep_sede_id' => $epSedeId]
            );
        }

        // Resolver expediente
        $exp = null; $metaNF = [];
        if ($request->filled('expediente_id')) {
            $exp = ExpedienteAcademico::where('ep_sede_id', $epSedeId)
                ->where('id', $request->integer('expediente_id'))
                ->where('estado', 'ACTIVO')
                ->first();
        } else {
            $codigo = (string) $request->input('codigo', '');
            [$exp, $metaNF] = $this->resolveExpedienteSmart($epSedeId, $codigo);
            if ($exp && $exp->estado !== 'ACTIVO') {
                $exp = null;
            }
        }

        if (!$exp) {
            return $this->fail(
                'EXPEDIENTE_NO_ACTIVO',
                'No se encontró un expediente ACTIVO en la EP-SEDE del evento.',
                422,
                $metaNF
            );
        }

        // Validar estado del evento
        if (!in_array($evento->estado, ['PLANIFICADO', 'EN_CURSO'])) {
            return $this->fail(
                'EVENT_NOT_ACTIVE',
                'El evento no admite inscripciones.',
                422,
                ['estado' => $evento->estado]
            );
        }

        // Debe requerir inscripción
        $requiereInscripcion = (bool) $evento->requiere_inscripcion;
        if (!$requiereInscripcion) {
            return $this->fail(
                'REGISTRATION_NOT_REQUIRED',
                'Este evento no requiere inscripción previa.',
                422
            );
        }

        // Ventana de inscripción
        $now = Carbon::now();

        if ($evento->inscripcion_desde) {
            $desde = Carbon::parse($evento->inscripcion_desde);
            if ($now->lt($desde)) {
                return $this->fail(
                    'REGISTRATION_NOT_OPEN',
                    'La inscripción aún no está abierta para este evento.',
                    422,
                    ['inscripcion_desde' => $evento->inscripcion_desde]
                );
            }
        }

        if ($evento->inscripcion_hasta) {
            $hasta = Carbon::parse($evento->inscripcion_hasta);
            if ($now->gt($hasta)) {
                return $this->fail(
                    'REGISTRATION_CLOSED',
                    'La inscripción para este evento ya ha finalizado.',
                    422,
                    ['inscripcion_hasta' => $evento->inscripcion_hasta]
                );
            }
        }

        // Ya inscrito
        $yaInscrito = VmParticipacion::where([
            'participable_type' => VmEvento::class,
            'participable_id'   => $evento->id,
            'expediente_id'     => $exp->id,
        ])->exists();

        if ($yaInscrito) {
            return $this->fail('ALREADY_ENROLLED', 'El alumno ya está inscrito en este evento.');
        }

        // Cupo máximo
        $cupoMax = $evento->cupo_maximo ? (int) $evento->cupo_maximo : null;
        if ($cupoMax) {
            $inscritos = VmParticipacion::where('participable_type', VmEvento::class)
                ->where('participable_id', $evento->id)
                ->count();
            if ($inscritos >= $cupoMax) {
                return $this->fail(
                    'EVENT_FULL',
                    'El evento ya alcanzó el cupo máximo de participantes.',
                    422,
                    ['cupo_maximo' => $cupoMax]
                );
            }
        }

        // Crear participación
        $part = VmParticipacion::create([
            'participable_type' => VmEvento::class,
            'participable_id'   => $evento->id,
            'expediente_id'     => $exp->id,
            'rol'               => 'ALUMNO',
            'estado'            => 'INSCRITO',
        ]);

        return response()->json([
            'ok'   => true,
            'code' => 'ENROLLED_MANUALLY',
            'data' => [
                'participacion' => $part,
                'evento'        => [
                    'id'                   => (int) $evento->id,
                    'requiere_inscripcion' => $requiereInscripcion,
                    'cupo_maximo'          => $cupoMax,
                ],
                'alumno' => [
                    'expediente_id'     => $exp->id,
                    'codigo_estudiante' => $exp->codigo_estudiante,
                ],
            ],
        ], 201);
    }

    // ========================= 7) Actualizar estado de participación en evento =========================
    //
    // PATCH /api/vm/inspeccion/eventos/participaciones/{participacion}
    //
    // Body:
    //  - estado: INSCRITO | CONFIRMADO | FINALIZADO | CANCELADO
    //
    public function actualizarEstadoParticipacionEvento(Request $request, VmParticipacion $participacion): JsonResponse
    {
        $actor = $this->requireAuth($request);

        // solo participaciones de eventos
        if ($participacion->participable_type !== VmEvento::class) {
            return $this->fail('NOT_EVENT_PARTICIPATION', 'La participación no corresponde a un evento.', 422);
        }

        $evento = VmEvento::find($participacion->participable_id);
        if (!$evento) {
            return $this->fail('EVENT_NOT_FOUND', 'Evento no encontrado.', 404);
        }

        $epSedeId = $this->epSedeIdFromEvento($evento);
        if (!$epSedeId || !EpScopeService::userManagesEpSede($actor->id, $epSedeId)) {
            return $this->fail('NOT_AUTHORIZED_FOR_EP_SEDE', 'No autorizado para esta EP-SEDE.', 403);
        }

        $v = Validator::make($request->all(), [
            'estado' => ['required', 'string'],
        ]);

        if ($v->fails()) {
            return response()->json(['ok' => false, 'errors' => $v->errors()], 422);
        }

        $estado = strtoupper((string) $request->input('estado'));
        $permitidos = ['INSCRITO', 'CONFIRMADO', 'FINALIZADO', 'CANCELADO'];

        if (!in_array($estado, $permitidos, true)) {
            return $this->fail(
                'INVALID_STATE',
                'Estado de participación no permitido.',
                422,
                ['permitidos' => $permitidos]
            );
        }

        $participacion->estado = $estado;
        $participacion->save();

        return response()->json([
            'ok'   => true,
            'code' => 'EVENT_PARTICIPATION_UPDATED',
            'data' => [
                'participacion_id' => (int) $participacion->id,
                'estado'           => $participacion->estado,
                'evento_id'        => (int) $evento->id,
            ],
        ], 200);
    }
}
