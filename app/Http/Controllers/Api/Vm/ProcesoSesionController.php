<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vm\SesionBatchRequest;
use App\Http\Resources\Vm\VmProcesoResource;
use App\Http\Resources\Vm\VmSesionResource;
use App\Models\VmProceso;
use App\Models\VmSesion;
use App\Services\Auth\EpScopeService;
use App\Services\Vm\PlanificacionService;
use App\Services\Vm\SesionBatchService;
use App\Support\DateList;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ProcesoSesionController extends Controller
{
    public function __construct(private PlanificacionService $plan) {}

    /**
     * GET /api/vm/procesos/{proceso}/contexto-edicion
     * @param VmProceso $proceso
     * @return JsonResponse
     */
    public function edit(VmProceso $proceso): JsonResponse
    {
        $user = request()->user();

        $proyecto = $proceso->proyecto()->firstOrFail();
        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        // Cargar ciclos y sesiones ordenadas; incluye niveles por sesión
        $proceso->load([
            'proyecto.ciclos',
            'sesiones' => fn($q) => $q
                ->with(['ciclos:id,nivel'])
                ->orderBy('fecha')
                ->orderBy('hora_inicio'),
        ]);

        return response()->json([
            'ok'   => true,
            'data' => [
                'proceso'  => new VmProcesoResource($proceso),
                'sesiones' => VmSesionResource::collection($proceso->sesiones),
            ],
        ], 200);
    }

    /**
     * POST /api/vm/procesos/{proceso}/sesiones/batch
     * @param VmProceso $proceso
     * @param SesionBatchRequest $request
     * @return JsonResponse
     */
    public function storeBatch(VmProceso $proceso, SesionBatchRequest $request): JsonResponse
    {
        $user = $request->user();

        /** @var \App\Models\VmProyecto $proyecto */
        $proyecto = $proceso->proyecto()->with(['periodo','ciclos'])->firstOrFail();

        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        if ($proyecto->estado !== 'PLANIFICADO') {
            return response()->json([
                'ok' => false,
                'message' => 'El proyecto no está en PLANIFICADO. No se pueden crear sesiones.',
            ], 409);
        }

        $payload = $request->validated();

        // Fechas del payload como YYYY-MM-DD (solo fechas, sin horas)
        /** @var \Illuminate\Support\Collection<string> $fechas */
        $fechas = DateList::fromBatchPayload($payload);

        // Límite del período del proyecto (robusto a string/Carbon)
        $iniRaw = $proyecto->periodo->fecha_inicio;
        $finRaw = $proyecto->periodo->fecha_fin;

        try {
            $ini = $iniRaw instanceof \Carbon\CarbonInterface ? $iniRaw->toDateString() : Carbon::parse($iniRaw)->toDateString();
            $fin = $finRaw instanceof \Carbon\CarbonInterface ? $finRaw->toDateString() : Carbon::parse($finRaw)->toDateString();
        } catch (\Throwable) {
            // Fallback defensivo ante formatos inesperados
            $ini = (string)$iniRaw;
            $fin = (string)$finRaw;
        }

        // Verificación de rango
        $fuera = $fechas->filter(fn($f) => !($ini <= $f && $f <= $fin))->values();
        if ($fuera->isNotEmpty()) {
            return response()->json([
                'ok'          => false,
                'message'     => 'Hay fechas fuera del período del proyecto.',
                'rango'       => [$ini, $fin],
                'fechas_fuera'=> $fuera,
            ], 422);
        }

        // Creación batch (sin concatenar fecha+hora en PHP)
        /** @var EloquentCollection<int,VmSesion> $created */
        $created = new EloquentCollection(SesionBatchService::createFor($proceso, $payload));

        // Asociar niveles si llegan
        $niveles = collect($request->get('niveles', []))
            ->map(fn($n) => (int)$n)->filter()->unique()->values();

        if ($niveles->isNotEmpty()) {
            /** @var \Illuminate\Support\Collection<int,int> $map  nivel => id */
            $map = $proyecto->ciclos->pluck('id', 'nivel');
            if ($map->only($niveles)->count() !== $niveles->count()) {
                return response()->json(['ok' => false, 'message' => 'Algún ciclo no pertenece al proyecto.'], 422);
            }
            $ids = $map->only($niveles)->values()->all();

            /** @var VmSesion $s */
            foreach ($created as $s) {
                $s->ciclos()->syncWithoutDetaching($ids);
            }
        }

        // Devolver sesiones con ciclos cargados
        $created->each(function (VmSesion $s) {
            $s->loadMissing('ciclos:id,nivel');
        });

        // Recalcular plan
        $this->plan->recalcularYSincronizar($proyecto);

        return response()->json(['ok' => true, 'data' => VmSesionResource::collection($created)], 201);
    }

    /**
     * GET /api/vm/sesiones/{sesion}/edit
     * @param VmSesion $sesion
     * @return JsonResponse
     */
    public function editSesion(VmSesion $sesion): JsonResponse
    {
        $user = request()->user();

        /** @var VmProceso $proceso */
        $proceso  = VmProceso::findOrFail($sesion->sessionable_id);
        $proyecto = $proceso->proyecto()->firstOrFail();

        if (!EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        // Importante: no reasignar $sesion con el retorno de loadMissing (para el IDE)
        $sesion->loadMissing('ciclos');

        return response()->json(['ok' => true, 'data' => new VmSesionResource($sesion)], 200);
    }

    /**
     * PUT /api/vm/sesiones/{sesion}
     * @param Request $request
     * @param VmSesion $sesion
     * @return JsonResponse
     */
    public function updateSesion(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();

        /** @var VmProceso $proceso */
        $proceso  = VmProceso::findOrFail($sesion->sessionable_id);
        /** @var \App\Models\VmProyecto $proyecto */
        $proyecto = $proceso->proyecto()->with('ciclos')->firstOrFail();

        if (!EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        if ($proyecto->estado !== 'PLANIFICADO') {
            return response()->json([
                'ok' => false,
                'message' => 'El proyecto no está en PLANIFICADO. No se puede editar la sesión.',
            ], 409);
        }

        // Validaciones (HH:mm)
        $rules = [
            'fecha'        => ['sometimes','date'],
            'hora_inicio'  => ['sometimes','date_format:H:i'],
            'hora_fin'     => ['sometimes','date_format:H:i'],
            'lugar'        => ['sometimes','nullable','string','max:255'],
            'enlace'       => ['sometimes','nullable','string','max:255'],
            'observacion'  => ['sometimes','nullable','string'],
            'niveles'      => ['sometimes','array'],
            'niveles.*'    => ['integer','between:1,10','distinct'],
        ];
        if ($request->filled('hora_inicio')) {
            $rules['hora_fin'][] = 'after:hora_inicio';
        }
        /** @var array<string,mixed> $data */
        $data = $request->validate($rules);

        // Caso: solo hora_fin (asegurar que > hora_inicio actual)
        if ($request->filled('hora_fin') && !$request->filled('hora_inicio')) {
            $hi = (string)$sesion->hora_inicio; // almacenado como HH:MM:SS
            $hiCmp = substr($hi, 0, 5);         // HH:MM
            if ($hiCmp && $request->string('hora_fin')->toString() <= $hiCmp) {
                return response()->json(['ok' => false, 'message' => 'hora_fin debe ser posterior a hora_inicio.'], 422);
            }
        }

        // Chequear “editabilidad” con target (fecha/hora resultantes)
        $targetFecha = (string) ($data['fecha'] ?? (string) $sesion->fecha);
        $targetHiRaw = (string) ($data['hora_inicio'] ?? (string) $sesion->hora_inicio);
        $targetHi    = preg_match('/^\d{2}:\d{2}$/', $targetHiRaw) ? $targetHiRaw : substr($targetHiRaw, 0, 5);

        // Clon temporal SOLO para validar (no reasignar $sesion)
        /** @var VmSesion $tmp */
        $tmp = $sesion->replicate();
        $tmp->fecha = $targetFecha;
        $tmp->hora_inicio = $targetHi . ':00'; // normaliza a HH:MM:SS para helper

        if (!$this->sesionEditable($tmp, (string)$proyecto->estado)) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se puede editar: la sesión es pasada o ya inició hoy.',
            ], 409);
        }

        // Actualizar (sin niveles)
        $sesion->update(collect($data)->except('niveles')->all());

        // Sincronizar niveles si llegaron
        if ($request->has('niveles')) {
            $niveles = collect($request->get('niveles', []))
                ->map(fn($n) => (int)$n)->filter()->unique()->values();

            $map = $proyecto->ciclos->pluck('id','nivel'); // nivel => id
            if ($map->only($niveles)->count() !== $niveles->count()) {
                return response()->json(['ok' => false, 'message' => 'Algún ciclo no pertenece al proyecto.'], 422);
            }

            $sesion->ciclos()->sync($map->only($niveles)->values()->all());
        }

        // Recalcular plan
        $this->plan->recalcularYSincronizar($proyecto);

        $sesion->loadMissing('ciclos');

        return response()->json(['ok' => true, 'data' => new VmSesionResource($sesion)], 200);
    }

    /**
     * DELETE /api/vm/sesiones/{sesion}
     * @param VmSesion $sesion
     * @return JsonResponse
     */
    public function destroySesion(VmSesion $sesion): JsonResponse
    {
        $user = request()->user();

        /** @var VmProceso $proceso */
        $proceso  = VmProceso::findOrFail($sesion->sessionable_id);
        $proyecto = $proceso->proyecto()->firstOrFail();

        if (!EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        if (!$this->sesionEditable($sesion, (string)$proyecto->estado)) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se puede eliminar: el proyecto no está en PLANIFICADO o la sesión ya inició/pasó.',
            ], 409);
        }

        $sesion->delete();

        // Recalcular plan
        $this->plan->recalcularYSincronizar($proyecto);

        return response()->json(null, 204);
    }

    /**
     * Helper interno: editable solo si proyecto PLANIFICADO y la sesión no pasó/ni inició.
     * @param VmSesion $sesion
     * @param string   $estadoProyecto
     * @return bool
     */
    protected function sesionEditable(VmSesion $sesion, string $estadoProyecto): bool
    {
        if ($estadoProyecto !== 'PLANIFICADO') {
            return false;
        }

        $today = now()->toDateString();
        $now   = now()->format('H:i:s');

        // fecha (cast date) → Y-m-d
        $fechaStr = $sesion->fecha instanceof \Carbon\CarbonInterface
            ? $sesion->fecha->toDateString()
            : (string)$sesion->fecha;

        // hora_inicio en BD puede estar como HH:MM:SS
        $hi = (string)$sesion->hora_inicio;

        if ($fechaStr < $today) return false;
        if ($fechaStr === $today && $hi && $hi <= $now) return false;

        return true;
    }
}
