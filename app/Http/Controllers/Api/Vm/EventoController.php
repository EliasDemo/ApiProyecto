<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vm\EventoStoreRequest;
use App\Http\Resources\Vm\VmEventoResource;
use App\Models\PeriodoAcademico;
use App\Models\VmEvento;
use App\Services\Auth\EpScopeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventoController extends Controller
{
    /** ✅ POST /api/vm/eventos (crea evento + sesiones) */
    public function store(EventoStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        // 🔐 Determinar EP-SEDE del usuario
        $epSedeId = EpScopeService::epSedeIdForUser($user->id);
        if (!$epSedeId) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo determinar tu EP-SEDE activa.',
            ], 403);
        }

        // 🧮 Validar periodo
        $periodo = PeriodoAcademico::findOrFail((int) $data['periodo_id']);
        $inicio  = Carbon::parse($periodo->fecha_inicio);
        $fin     = Carbon::parse($periodo->fecha_fin);

        // Validar sesiones (rango de fechas + horas coherentes)
        $sesiones = $data['sesiones'] ?? [];
        if (!count($sesiones)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Debes registrar al menos una sesión.',
            ], 422);
        }

        foreach ($sesiones as $s) {
            $fecha = Carbon::parse($s['fecha']);

            if (!$fecha->betweenIncluded($inicio, $fin)) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Una o más sesiones están fuera del rango del período académico.',
                ], 422);
            }

            // hora_fin > hora_inicio (formato HH:mm)
            if (($s['hora_fin'] ?? '') <= ($s['hora_inicio'] ?? '')) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'En cada sesión la hora de fin debe ser mayor que la hora de inicio.',
                ], 422);
            }
        }

        $codigo = $data['codigo'] ?? ('EVT-' . now()->format('YmdHis') . '-' . $user->id);

        // ✅ Transacción: evento + sesiones
        DB::beginTransaction();

        try {
            $evento = VmEvento::create([
                'periodo_id'          => $data['periodo_id'],
                'categoria_evento_id' => $data['categoria_evento_id'] ?? null,
                'targetable_type'     => 'ep_sede',
                'targetable_id'       => $epSedeId,
                'codigo'              => $codigo,
                'titulo'              => $data['titulo'],
                'subtitulo'           => $data['subtitulo'] ?? null,
                'descripcion_corta'   => $data['descripcion_corta'] ?? null,
                'descripcion_larga'   => $data['descripcion_larga'] ?? null,
                'modalidad'           => $data['modalidad'] ?? 'PRESENCIAL',
                'lugar_detallado'     => $data['lugar_detallado'] ?? null,
                'url_imagen_portada'  => $data['url_imagen_portada'] ?? null,
                'url_enlace_virtual'  => $data['url_enlace_virtual'] ?? null,
                'requiere_inscripcion'=> (bool) ($data['requiere_inscripcion'] ?? false),
                'cupo_maximo'         => $data['cupo_maximo'] ?? null,
                'inscripcion_desde'   => $data['inscripcion_desde'] ?? null,
                'inscripcion_hasta'   => $data['inscripcion_hasta'] ?? null,
                'estado'              => 'PLANIFICADO',
            ]);

            foreach ($sesiones as $s) {
                $evento->sesiones()->create([
                    'fecha'       => $s['fecha'],
                    'hora_inicio' => $s['hora_inicio'],
                    'hora_fin'    => $s['hora_fin'],
                    'estado'      => 'PLANIFICADO',
                ]);
            }

            DB::commit();

            $evento->load(['periodo', 'categoria', 'sesiones']);

            return response()->json([
                'ok'   => true,
                'data' => new VmEventoResource($evento),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'ok'      => false,
                'message' => 'Error al crear el evento: ' . $e->getMessage(),
            ], 500);
        }
    }

    /** ✅ GET /api/vm/eventos (lista árbol evento + sesiones) */
    public function index(Request $request): JsonResponse
    {
        $query = VmEvento::query()
            ->with([
                'periodo',
                'categoria',
                'sesiones' => fn ($q) => $q->orderBy('fecha')->orderBy('hora_inicio'),
            ])
            ->latest('created_at');

        if ($estado = $request->get('estado')) {
            $query->where('estado', $estado);
        }

        if ($request->boolean('solo_mi_ep_sede') && $request->user()) {
            $epSedeId = EpScopeService::epSedeIdForUser($request->user()->id);
            $query
                ->where('targetable_type', 'ep_sede')
                ->where('targetable_id', $epSedeId);
        }

        $eventos = $query->paginate(15);

        return response()->json([
            'ok'   => true,
            'data' => VmEventoResource::collection($eventos),
            'meta' => ['total' => $eventos->total()],
        ]);
    }

    /** ✅ GET /api/vm/eventos/{evento} */
    public function show(VmEvento $evento): JsonResponse
    {
        $evento->load(['periodo', 'categoria', 'sesiones']);

        return response()->json([
            'ok'   => true,
            'data' => new VmEventoResource($evento),
        ]);
    }

    /** ✅ PUT /api/vm/eventos/{evento} */
    public function update(Request $request, VmEvento $evento): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        if ($evento->estado === 'CERRADO') {
            return response()->json(['ok' => false, 'message' => 'No se puede editar un evento cerrado.'], 422);
        }

        $epSedeId = EpScopeService::epSedeIdForUser($user->id);
        if (!$epSedeId || (int) $evento->targetable_id !== $epSedeId) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para este evento.'], 403);
        }

        $data = $request->validate([
            'titulo'              => ['sometimes', 'string', 'max:255'],
            'subtitulo'           => ['nullable', 'string', 'max:255'],
            'descripcion_corta'   => ['nullable', 'string'],
            'descripcion_larga'   => ['nullable', 'string'],
            'categoria_evento_id' => ['nullable', 'exists:vm_categorias_evento,id'],
            'modalidad'           => ['sometimes', 'in:PRESENCIAL,VIRTUAL,MIXTA'],
            'lugar_detallado'     => ['nullable', 'string'],
            'url_imagen_portada'  => ['nullable', 'url'],
            'url_enlace_virtual'  => ['nullable', 'url'],
            'requiere_inscripcion'=> ['sometimes', 'boolean'],
            'cupo_maximo'         => ['nullable', 'integer', 'min:1'],
            'inscripcion_desde'   => ['nullable', 'date'],
            'inscripcion_hasta'   => ['nullable', 'date', 'after_or_equal:inscripcion_desde'],
            'estado'              => ['sometimes', 'in:PLANIFICADO,EN_CURSO,CERRADO,CANCELADO'],
        ]);

        $evento->fill($data)->save();

        $evento->load(['periodo', 'categoria', 'sesiones']);

        return response()->json([
            'ok'   => true,
            'data' => new VmEventoResource($evento),
        ]);
    }

    /** ✅ DELETE /api/vm/eventos/{evento} (solo si PLANIFICADO) */
    public function destroy(Request $request, VmEvento $evento): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        $epSedeId = EpScopeService::epSedeIdForUser($user->id);
        if (!$epSedeId || (int) $evento->targetable_id !== $epSedeId) {
            return response()->json(['ok' => false, 'message' => 'No autorizado.'], 403);
        }

        if ($evento->estado !== 'PLANIFICADO') {
            return response()->json(['ok' => false, 'message' => 'Solo se pueden eliminar eventos planificados.'], 422);
        }

        DB::transaction(function () use ($evento) {
            $evento->sesiones()->delete();
            $evento->delete();
        });

        return response()->json(['ok' => true, 'message' => 'Evento eliminado correctamente.']);
    }
}
