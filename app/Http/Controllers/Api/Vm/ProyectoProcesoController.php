<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vm\ProcesoStoreRequest;
use App\Http\Resources\Vm\VmProcesoResource;
use App\Models\VmProceso;
use App\Models\VmProyecto;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProyectoProcesoController extends Controller
{
    public function store(VmProyecto $proyecto, ProcesoStoreRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'No autorizado para la EP_SEDE del proyecto.'], 403);
        }

        $data = $request->validated();

        if (empty($data['orden'])) {
            $max = (int) ($proyecto->procesos()->max('orden') ?? 0);
            $data['orden'] = $max + 1;
        }

        $proc = DB::transaction(fn () => $proyecto->procesos()->create($data));

        return response()->json(['ok'=>true, 'data'=>new VmProcesoResource($proc)], 201);
    }

    public function update(VmProceso $proceso, Request $request): JsonResponse
    {
        $user = $request->user();
        $proyecto = $proceso->proyecto()->firstOrFail();

        if (!EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        if (!$this->proyectoEditable($proyecto->id)) {
            return response()->json([
                'ok'=>false,
                'message'=>'El proyecto ya inició (o no está en PLANIFICADO). No se puede editar el proceso.',
            ], 409);
        }

        $data = $request->validate([
            'nombre'      => ['sometimes','string','max:255'],
            'descripcion' => ['sometimes','nullable','string'],
            'orden'       => ['sometimes','integer','min:1','max:65535'],
        ]);

        $proceso->update($data);

        return response()->json(['ok'=>true, 'data'=>new VmProcesoResource($proceso->fresh())], 200);
    }

    public function destroy(VmProceso $proceso): JsonResponse
    {
        $user = request()->user();
        $proyecto = $proceso->proyecto()->firstOrFail();

        if (!EpScopeService::userManagesEpSede($user->id, (int)$proyecto->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        if (!$this->procesoEliminable($proceso)) {
            return response()->json([
                'ok'=>false,
                'message'=>'No se puede eliminar: el proyecto no está en PLANIFICADO o hay sesiones pasadas/ya iniciadas.',
            ], 409);
        }

        $proceso->delete();

        return response()->json(null, 204);
    }

    protected function proyectoEditable(int $proyectoId): bool
    {
        $proj = VmProyecto::with(['procesos.sesiones'])->find($proyectoId);
        if (!$proj || $proj->estado !== 'PLANIFICADO') return false;

        $today = now()->toDateString();
        $now   = now()->format('H:i:s');

        $yaInicio = $proj->procesos->contains(function ($p) use ($today, $now) {
            return $p->sesiones->contains(function ($s) use ($today, $now) {
                $f = method_exists($s->fecha, 'toDateString') ? $s->fecha->toDateString() : (string)$s->fecha;
                return ($f < $today) || ($f === $today && $s->hora_inicio && $s->hora_inicio <= $now);
            });
        });

        return !$yaInicio;
    }

    protected function procesoEliminable(VmProceso $proceso): bool
    {
        $proyecto = $proceso->proyecto()->first();
        if (!$proyecto || $proyecto->estado !== 'PLANIFICADO') return false;

        $today = now()->toDateString();
        $now   = now()->format('H:i:s');

        $yaInicio = $proceso->sesiones()
            ->whereDate('fecha', '<', $today)
            ->orWhere(function ($q) use ($today, $now) {
                $q->whereDate('fecha', $today)
                  ->where('hora_inicio', '<=', $now);
            })
            ->exists();

        return !$yaInicio;
    }
}
