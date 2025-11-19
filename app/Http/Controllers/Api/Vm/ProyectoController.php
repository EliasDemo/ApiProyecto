<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vm\ProyectoStoreRequest;
use App\Http\Resources\Vm\VmProcesoResource;
use App\Http\Resources\Vm\VmProyectoResource;
use App\Models\ExpedienteAcademico;
use App\Models\PeriodoAcademico;
use App\Models\VmParticipacion;
use App\Models\VmProyecto;
use App\Models\VmProyectoCiclo;
use App\Services\Auth\EpScopeService;
use App\Services\Vm\PlanificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ProyectoController extends Controller
{
    public function __construct(private PlanificacionService $plan) {}

    /** GET /api/vm/proyectos (gestión) */
    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $q      = trim((string) $request->get('q', ''));
        $nivel  = $request->filled('nivel') ? (int) $request->get('nivel') : null;
        $estado = $request->get('estado');
        $perId  = $request->get('periodo_id');
        $epId   = $request->get('ep_sede_id');

        // expand=procesos,sesiones o expand=arbol
        $expand    = collect(array_filter(array_map('trim', explode(',', strtolower($request->query('expand', ''))))));
        $withTree  = $request->boolean('with_tree', false) || $expand->contains('arbol');
        $withProcs = $withTree || $expand->contains('procesos');
        $withSess  = $withTree || $expand->contains('sesiones');

        $query = VmProyecto::query()
            ->with(['imagenes' => fn ($q2) => $q2->latest()->limit(5)])
            ->with('ciclos')
            ->withCount('imagenes as imagenes_total');

        // Limita por EP_SEDE gestionadas por el usuario
        $ids = EpScopeService::epSedesIdsManagedBy((int) $user->id);
        if (!empty($ids)) {
            $query->whereIn('ep_sede_id', $ids);
        } else {
            $query->whereRaw('1=0');
        }

        if ($q !== '') {
            $query->where(fn($qq) => $qq
                ->where('titulo', 'like', "%{$q}%")
                ->orWhere('codigo', 'like', "%{$q}%")
            );
        }
        if (!is_null($nivel))   $query->whereHas('ciclos', fn ($qq) => $qq->where('nivel', $nivel));
        if (!empty($estado))    $query->where('estado', $estado);
        if (!empty($perId))     $query->where('periodo_id', $perId);
        if (!empty($epId))      $query->where('ep_sede_id', $epId);

        if ($withProcs) {
            $query->with(['procesos' => function ($q) {
                $q->orderBy('orden')->orderBy('id');
            }]);
        }
        if ($withSess) {
            $query->with(['procesos.sesiones' => function ($q) {
                $q->orderBy('fecha')->orderBy('hora_inicio');
            }]);
        }

        $page = $query->latest('id')->paginate(15)->withQueryString();

        // Si pidieron árbol, devolvemos mismo shape que show()
        $page->getCollection()->transform(function ($item) use ($request, $withProcs, $withSess) {
            $base = (new VmProyectoResource($item))->toArray($request);

            if ($withProcs || $withSess) {
                return [
                    'proyecto' => $base,
                    'procesos' => VmProcesoResource::collection($item->procesos)->toArray($request),
                ];
            }

            return $base;
        });

        return response()->json(['ok' => true, 'data' => $page], 200);
    }

    /** GET /api/vm/proyectos/{proyecto} (vista pública/rol alumno con restricciones) */
    public function show(VmProyecto $proyecto): JsonResponse
    {
        $user = request()->user();

        if (!EpScopeService::userBelongsToEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'No perteneces a esta EP_SEDE.'], 403);
        }

        // Si está PLANIFICADO, solo staff o alumno inscrito lo ven
        $expIdForUser = ExpedienteAcademico::where('user_id', $user->id)
            ->where('ep_sede_id', $proyecto->ep_sede_id)
            ->value('id');

        $inscrito = $expIdForUser
            ? $proyecto->participaciones()->where('expediente_id', $expIdForUser)->exists()
            : false;

        if ($proyecto->estado === 'PLANIFICADO'
            && !$inscrito
            && !EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'Proyecto aún no publicado.'], 403);
        }

        $proyecto->load([
            'imagenes',
            'ciclos',
            'procesos' => fn($q) => $q->orderBy('orden')->orderBy('id'),
            'procesos.sesiones' => fn($q) => $q->orderBy('fecha')->orderBy('hora_inicio'),
        ]);

        return response()->json([
            'ok'   => true,
            'data' => [
                'proyecto' => new VmProyectoResource($proyecto),
                'procesos' => VmProcesoResource::collection($proyecto->procesos),
            ],
        ], 200);
    }

    /** GET /api/vm/proyectos/alumno (listado para estudiante) */
    public function indexAlumno(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1) Expediente del alumno
        $expQuery = ExpedienteAcademico::query()
            ->when(Schema::hasColumn('expedientes_academicos', 'user_id'), fn($q)=>$q->where('user_id', $user->id))
            ->when(Schema::hasColumn('expedientes_academicos', 'usuario_id'), fn($q)=>$q->where('usuario_id', $user->id))
            ->when(method_exists(ExpedienteAcademico::class, 'scopeActivos'), fn($q)=>$q->activos())
            ->orderByDesc('id');

        $exp = $expQuery->first();

        if (!$exp) {
            return response()->json(['ok'=>false, 'message'=>'No se encontró expediente del alumno.'], 422);
        }
        $epSedeId = (int) $exp->ep_sede_id;

        // 2) Período seleccionado (o vigente/último)
        $periodo = $request->filled('periodo_id')
            ? PeriodoAcademico::find((int)$request->get('periodo_id'))
            : null;

        if (!$periodo) {
            $periodo = PeriodoAcademico::whereDate('fecha_inicio','<=', now())
                ->whereDate('fecha_fin','>=', now())->first()
                ?: PeriodoAcademico::orderByDesc('fecha_inicio')->first();
        }
        if (!$periodo) {
            return response()->json(['ok'=>false, 'message'=>'No hay periodos académicos definidos.'], 422);
        }

        // ¿El período seleccionado está vigente hoy?
        $esVigente = false;
        try {
            $ini = Carbon::parse($periodo->fecha_inicio);
            $fin = Carbon::parse($periodo->fecha_fin);
            $esVigente = Carbon::now()->betweenIncluded($ini, $fin);
        } catch (\Throwable $e) {
            $esVigente = false;
        }

        // 3) Ciclo actual del alumno (ciclo = nivel)
        $matriculaActual = DB::table('matriculas')
            ->join('periodos_academicos', 'periodos_academicos.id', '=', 'matriculas.periodo_id')
            ->where('matriculas.expediente_id', $exp->id)
            ->where(function($q){
                if (Schema::hasColumn('periodos_academicos','es_actual')) {
                    $q->where('periodos_academicos.es_actual', true);
                } else {
                    $q->whereDate('periodos_academicos.fecha_inicio','<=', now())
                      ->whereDate('periodos_academicos.fecha_fin','>=', now());
                }
            })
            ->select('matriculas.ciclo')
            ->first();

        $cicloActual = (int) (($matriculaActual->ciclo ?? $exp->ciclo) ?? 1);

        // 4) ¿Ya tiene VINCULADO en su ciclo actual (cualquier período)?
        $tieneVinculadoEnCiclo = VmParticipacion::query()
            ->join('vm_proyectos as p', 'p.id', '=', 'vm_participaciones.participable_id')
            ->join('vm_proyecto_ciclos as pc', 'pc.proyecto_id', '=', 'p.id')
            ->where('vm_participaciones.expediente_id', $exp->id)
            ->whereIn('vm_participaciones.estado', ['INSCRITO','CONFIRMADO','EN_CURSO','FINALIZADO'])
            ->where('p.ep_sede_id', $epSedeId)
            ->whereIn('p.tipo', ['VINCULADO','PROYECTO'])
            ->where('pc.nivel', $cicloActual)
            ->exists();

        // 5) Participaciones del alumno (del período seleccionado) → pendientes + actual
        $parts = VmParticipacion::query()
            ->join('vm_proyectos as pr', 'pr.id', '=', 'vm_participaciones.participable_id')
            ->where('vm_participaciones.expediente_id', $exp->id)
            ->where('pr.ep_sede_id', $epSedeId)
            ->where('pr.periodo_id', $periodo->id)
            ->select(
                'vm_participaciones.*',
                'pr.id as proyecto_id',
                'pr.tipo as proyecto_tipo',
                'pr.estado as proyecto_estado',
                'pr.periodo_id as proyecto_periodo_id'
            )
            ->get();

        $pendientes = [];
        $actualProyecto = null;

        foreach ($parts as $p) {
            $projId  = (int) $p->proyecto_id;
            $tipo    = strtoupper((string) $p->proyecto_tipo);
            $estadoP = (string) $p->proyecto_estado;

            if ($tipo === 'PROYECTO') $tipo = 'VINCULADO';
            if ($tipo !== 'VINCULADO') continue;

            $proj = VmProyecto::find($projId);
            if (!$proj) continue;

            $req = $this->minutosRequeridosProyecto($proj);
            // 👇 Solo minutos del ciclo actual del alumno
            $acc = $this->minutosValidadosProyectoPorCiclo($projId, (int)$exp->id, $cicloActual);

            if ($acc < $req) {
                $pendientes[] = [
                    'proyecto'       => (new VmProyectoResource($proj->loadMissing('imagenes','ciclos')))->toArray($request),
                    'periodo'        => optional($proj->periodo)->codigo ?? $proj->periodo_id,
                    'requerido_min'  => $req,
                    'acumulado_min'  => $acc,
                    'faltan_min'     => max(0, $req - $acc),
                    'cerrado'        => in_array(strtoupper($estadoP), ['CERRADO','CANCELADO','FINALIZADO']),
                ];

                if (!$actualProyecto && $estadoP === 'EN_CURSO') {
                    $actualProyecto = $proj;
                }
            }
        }

        $tienePendienteVinculado = count($pendientes) > 0;

        // 6) Bases (todas del período seleccionado)
        $base = VmProyecto::query()
            ->where('ep_sede_id', $epSedeId)
            ->where('periodo_id', $periodo->id)
            ->with(['imagenes' => fn ($q) => $q->latest()->limit(5)])
            ->with('ciclos')
            ->withCount('imagenes as imagenes_total');

        // a) VINCULADOS inscribibles SOLO si el período está vigente
        $inscribibles = collect();
        if ($esVigente && !$tienePendienteVinculado && !$tieneVinculadoEnCiclo) {
            $inscribibles = (clone $base)
                ->whereIn('estado', ['PLANIFICADO','EN_CURSO'])
                ->whereIn('tipo', ['VINCULADO','PROYECTO'])
                ->whereHas('ciclos', fn ($q) => $q->where('nivel', $cicloActual))
                ->whereDoesntHave('participaciones', fn($q) => $q->where('expediente_id', $exp->id))
                ->orderByDesc('id')
                ->get();
        }

        // b) LIBRES
        $libres = (clone $base)
            ->where('tipo', 'LIBRE')
            ->orderByDesc('id')
            ->get();

        // c) Vinculados históricos
        $vinculadosHistoricos = (clone $base)
            ->whereIn('tipo', ['VINCULADO','PROYECTO'])
            ->orderByDesc('id')
            ->get();

        $toRes = fn($col) => $col->map(fn($p) => (new VmProyectoResource($p))->toArray($request));

        // 7) Respuesta
        $resp = [
            'contexto' => [
                'ep_sede_id'               => $epSedeId,
                'periodo_id'               => $periodo->id,
                'periodo_codigo'           => $periodo->codigo ?? $periodo->id,
                'ciclo_actual'             => $cicloActual,
                'tiene_pendiente_vinculado'=> $tienePendienteVinculado,
                'tiene_vinculado_en_ciclo' => $tieneVinculadoEnCiclo,
            ],
            'actual'                 => $actualProyecto
                ? (new VmProyectoResource($actualProyecto->loadMissing('imagenes','ciclos')))->toArray($request)
                : null,
            'pendientes'             => $pendientes,
            'inscribibles'           => $toRes($inscribibles),
            'libres'                 => $toRes($libres),
            'vinculados_historicos'  => $toRes($vinculadosHistoricos),
        ];

        return response()->json(['ok'=>true, 'data'=>$resp], 200);
    }

    /** POST /api/vm/proyectos */
    public function store(ProyectoStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (!EpScopeService::userManagesEpSede($user->id, (int)$data['ep_sede_id'])) {
            return response()->json(['ok'=>false,'message'=>'No autorizado para esta EP_SEDE.'], 403);
        }

        $codigo = $data['codigo'] ?: sprintf('PRJ-%s-EP%s-%s',
            now()->format('YmdHis'), $data['ep_sede_id'], $user->id
        );

        $tipo = strtoupper($data['tipo']);

        $proyecto = DB::transaction(function () use ($data, $codigo, $tipo) {
            $p = VmProyecto::create([
                'ep_sede_id'                 => $data['ep_sede_id'],
                'periodo_id'                 => $data['periodo_id'],
                'codigo'                     => $codigo,
                'titulo'                     => $data['titulo'],
                'descripcion'                => $data['descripcion'] ?? null,
                'tipo'                       => $tipo,
                'modalidad'                  => $data['modalidad'],
                'estado'                     => 'PLANIFICADO',
                'horas_planificadas'         => $data['horas_planificadas'],
                'horas_minimas_participante' => $data['horas_minimas_participante'] ?? null,
            ]);

            if (in_array($tipo, ['VINCULADO','PROYECTO'], true)) {
                $niveles = collect($data['niveles'] ?? [])->unique()->values();
                $rows = $niveles->map(fn($n) => [
                    'proyecto_id' => $p->id,
                    'ep_sede_id'  => $p->ep_sede_id,
                    'periodo_id'  => $p->periodo_id,
                    'nivel'       => (int)$n,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ])->all();
                if ($rows) DB::table('vm_proyecto_ciclos')->insert($rows);
            }

            return $p;
        });

        return response()->json(['ok'=>true,'data'=>new VmProyectoResource($proyecto->loadMissing('ciclos'))], 201);
    }

    /** PUT /api/vm/proyectos/{proyecto}/publicar */
    public function publicar(VmProyecto $proyecto): JsonResponse
    {
        $user = request()->user();

        if (!EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'No autorizado para esta EP_SEDE.'], 403);
        }

        if ($proyecto->estado !== 'PLANIFICADO') {
            return response()->json([
                'ok'=>false,
                'message'=>'Solo se puede publicar un proyecto en estado PLANIFICADO.',
            ], 409);
        }

        if ($proyecto->procesos()->count() < 1) {
            return response()->json([
                'ok'=>false,
                'message'=>'Debe definir al menos 1 proceso antes de publicar el proyecto.',
            ], 422);
        }

        // En VINCULADO/PROYECTO se exige al menos 1 ciclo
        if (in_array($proyecto->tipo, ['VINCULADO','PROYECTO'], true) && $proyecto->ciclos()->count() < 1) {
            return response()->json([
                'ok'=>false,
                'message'=>'Debe definir al menos 1 ciclo (nivel) antes de publicar el proyecto.',
            ], 422);
        }

        // 🔐 Validar cuadres antes de publicar
        try {
            $this->plan->assertCuadres($proyecto);
        } catch (ValidationException $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se puede publicar: la planificación no cuadra.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $proyecto->update(['estado' => 'EN_CURSO']);

        return response()->json(['ok'=>true, 'data'=>$proyecto->fresh()->loadMissing('ciclos')], 200);
    }

    /** GET /api/vm/proyectos/niveles-disponibles */
    public function nivelesDisponibles(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'ep_sede_id' => ['required','integer','exists:ep_sede,id'],
            'periodo_id' => ['required','integer','exists:periodos_academicos,id'],
            'exclude_proyecto_id' => ['nullable','integer','exists:vm_proyectos,id'],
        ]);
        if ($v->fails()) {
            return response()->json(['ok'=>false,'errors'=>$v->errors()], 422);
        }

        $ep = (int) $request->get('ep_sede_id');
        $per = (int) $request->get('periodo_id');
        $exclude = $request->get('exclude_proyecto_id');

        $user = $request->user();
        if (!EpScopeService::userManagesEpSede($user->id, $ep)) {
            return response()->json(['ok'=>false, 'message'=>'No autorizado para esta EP_SEDE.'], 403);
        }

        $ocupados = DB::table('vm_proyecto_ciclos')
            ->where('ep_sede_id', $ep)
            ->where('periodo_id', $per)
            ->when($exclude, fn($q) => $q->where('proyecto_id', '!=', $exclude))
            ->pluck('nivel')
            ->map(fn ($n) => (int) $n)
            ->all();

        $todos = range(1, 10);
        $disponibles = array_values(array_diff($todos, $ocupados));
        sort($disponibles);

        return response()->json(['ok'=>true, 'data'=>$disponibles], 200);
    }

    /** GET /api/vm/proyectos/{proyecto}/edit */
    public function edit(VmProyecto $proyecto): JsonResponse
    {
        $user = request()->user();
        if (!EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'No autorizado para esta EP_SEDE.'], 403);
        }

        $proyecto->load(['procesos.sesiones','ciclos']);

        return response()->json([
            'ok'   => true,
            'data' => [
                'proyecto' => new VmProyectoResource($proyecto),
                'procesos' => VmProcesoResource::collection($proyecto->procesos),
            ],
        ], 200);
    }

    /** PUT /api/vm/proyectos/{proyecto} */
    public function update(Request $request, VmProyecto $proyecto): JsonResponse
    {
        $user = $request->user();
        if (!EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'No autorizado para esta EP_SEDE.'], 403);
        }

        if (!$this->editable($proyecto)) {
            return response()->json([
                'ok'=>false,
                'message'=>'El proyecto ya inició (o no está en PLANIFICADO). No se puede editar.',
            ], 409);
        }

        $data = $request->validate([
            'titulo'                     => ['sometimes','string','max:255'],
            'descripcion'                => ['sometimes','nullable','string'],
            'tipo'                       => ['sometimes','in:VINCULADO,LIBRE,PROYECTO'],
            'modalidad'                  => ['sometimes','in:PRESENCIAL,VIRTUAL,MIXTA'],
            'horas_planificadas'         => ['sometimes','integer','min:1','max:32767'],
            'horas_minimas_participante' => ['sometimes','nullable','integer','min:0','max:32767'],

            // multiciclo
            'niveles'   => ['sometimes','array'],
            'niveles.*' => ['integer','between:1,10','distinct'],
        ]);

        DB::transaction(function () use ($proyecto, $data) {
            $proyecto->update(collect($data)->except(['niveles'])->all());

            if (array_key_exists('niveles', $data)) {
                $niveles = collect($data['niveles'])->unique()->values();

                // validar disponibilidad global (sede+periodo+nivel)
                foreach ($niveles as $n) {
                    $exists = DB::table('vm_proyecto_ciclos')
                        ->where('ep_sede_id', $proyecto->ep_sede_id)
                        ->where('periodo_id', $proyecto->periodo_id)
                        ->where('nivel', $n)
                        ->where('proyecto_id', '!=', $proyecto->id)
                        ->exists();
                    if ($exists) {
                        abort(422, "El nivel {$n} ya está ocupado en este período/sede.");
                    }
                }

                // sync
                $existentes = $proyecto->ciclos()->pluck('nivel','id'); // id => nivel
                $porCrear   = $niveles->diff($existentes->values());
                $porBorrar  = $existentes->filter(fn($n) => !$niveles->contains($n))->keys();

                if ($porBorrar->isNotEmpty()) {
                    VmProyectoCiclo::whereIn('id', $porBorrar)->delete(); // cascada borra vm_sesion_ciclos
                }

                foreach ($porCrear as $n) {
                    VmProyectoCiclo::create([
                        'proyecto_id' => $proyecto->id,
                        'ep_sede_id'  => $proyecto->ep_sede_id,
                        'periodo_id'  => $proyecto->periodo_id,
                        'nivel'       => (int) $n,
                    ]);
                }
            }
        });

        return response()->json(['ok'=>true,'data'=>new VmProyectoResource($proyecto->fresh()->loadMissing('ciclos'))], 200);
    }

    /** DELETE /api/vm/proyectos/{proyecto} */
    public function destroy(VmProyecto $proyecto): JsonResponse
    {
        $user = request()->user();
        if (!EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'No autorizado para esta EP_SEDE.'], 403);
        }

        if (!$this->editable($proyecto)) {
            return response()->json([
                'ok'=>false,
                'message'=>'El proyecto ya inició (o no está en PLANIFICADO). No se puede eliminar.',
            ], 409);
        }

        $proyecto->delete();
        return response()->json(null, 204);
    }

    /** editable/eliminable si:
     *  - estado === PLANIFICADO
     *  - no hay sesiones pasadas o ya iniciadas hoy
     */
    private function editable(VmProyecto $proyecto): bool
    {
        if ($proyecto->estado !== 'PLANIFICADO') {
            return false;
        }

        $today = Carbon::today()->toDateString();
        $now   = Carbon::now()->format('H:i:s');

        $yaInicio = $proyecto->procesos()
            ->whereHas('sesiones', function ($q) use ($today, $now) {
                $q->whereDate('fecha', '<', $today)
                  ->orWhere(function ($qq) use ($today, $now) {
                      $qq->whereDate('fecha', $today)
                         ->where('hora_inicio', '<=', $now);
                  });
            })
            ->exists();

        return !$yaInicio;
    }

    // ───────────────────────── helpers ─────────────────────────

    protected function minutosRequeridosProyecto(VmProyecto $proyecto): int
    {
        $h = $proyecto->horas_minimas_participante ?? $proyecto->horas_planificadas;
        return ((int)$h) * 60;
    }

    /** Minutos validados solo de sesiones del ciclo indicado. */
    protected function minutosValidadosProyectoPorCiclo(int $proyectoId, int $expedienteId, int $nivel): int
    {
        return (int) DB::table('vm_asistencias as a')
            ->join('vm_sesiones as s', 's.id', '=', 'a.sesion_id')
            ->join('vm_procesos as pr', 'pr.id', '=', 's.sessionable_id')
            ->join('vm_sesion_ciclos as sc', 'sc.sesion_id', '=', 's.id')
            ->join('vm_proyecto_ciclos as pc', 'pc.id', '=', 'sc.proyecto_ciclo_id')
            ->where('a.estado', 'VALIDADO')
            ->where('a.expediente_id', $expedienteId)
            ->where('pr.proyecto_id', $proyectoId)
            ->where('pc.nivel', $nivel)
            ->sum('a.minutos_validados');
    }
}
