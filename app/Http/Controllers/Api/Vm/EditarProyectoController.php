<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Vm\VmProyectoResource;
use App\Http\Resources\Vm\VmProcesoResource;
use App\Models\VmProyecto;
use App\Models\VmProyectoCiclo;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EditarProyectoController extends Controller
{
    /** GET /api/vm/proyectos/{proyecto}/edit */
    public function show(VmProyecto $proyecto): JsonResponse
    {
        $user = request()->user();
        if (!$user) {
            return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        }

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
        if (!$user) {
            return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        }
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
            $proyecto->update(collect($data)->except('niveles')->all());

            if (array_key_exists('niveles', $data)) {
                $niveles = collect($data['niveles'])->unique()->values();

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

                $existentes = $proyecto->ciclos()->pluck('nivel','id'); // id => nivel
                $porCrear   = $niveles->diff($existentes->values());
                $porBorrar  = $existentes->filter(fn($n) => !$niveles->contains($n))->keys();

                if ($porBorrar->isNotEmpty()) {
                    VmProyectoCiclo::whereIn('id', $porBorrar)->delete();
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
        if (!$user) {
            return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        }
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

    private function editable(VmProyecto $proyecto): bool
    {
        if ($proyecto->estado !== 'PLANIFICADO') return false;

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
}
