<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Vm\ImagenResource;
use App\Models\Imagen;
use App\Models\VmEvento;
use App\Services\Auth\EpScopeService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EventoImagenController extends Controller
{
    /**
     * GET /api/vm/eventos/{evento}/imagenes
     */
    public function index(VmEvento $evento): JsonResponse
    {
        $user = request()->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        if (!$this->userCanManageEvento($user->id, $evento)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para este evento.'], 403);
        }

        $imagenes = $evento->imagenes()->latest()->get();

        return response()->json([
            'ok'   => true,
            'data' => ImagenResource::collection($imagenes),
        ], 200);
    }

    /**
     * POST /api/vm/eventos/{evento}/imagenes
     * Body (multipart/form-data): file: <imagen>
     */
    public function store(Request $request, VmEvento $evento): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        if (!$this->userCanManageEvento($user->id, $evento)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para este evento.'], 403);
        }

        if (in_array($evento->estado, ['CERRADO', 'CANCELADO'], true)) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se pueden subir imágenes de un evento cerrado o cancelado.',
            ], 422);
        }

        $request->validate([
            'file' => ['required', 'file', 'image', 'max:5120'], // 5MB
        ]);

        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            throw ValidationException::withMessages([
                'file' => ['El archivo no es válido.'],
            ]);
        }

        $disk = 'public';
        $path = $file->store("eventos/{$evento->id}", $disk);

        /** @var FilesystemAdapter $fs */
        $fs = Storage::disk($disk);
        $absoluteUrl = $fs->url($path); // requiere storage:link

        $img = $evento->imagenes()->create([
            'disk'        => $disk,
            'path'        => $path,
            'url'         => $absoluteUrl,
            'titulo'      => null,
            'visibilidad' => 'PUBLICA',
            'subido_por'  => $user->id,
        ]);

        return response()->json([
            'ok'   => true,
            'data' => new ImagenResource($img->fresh()),
        ], 201);
    }

    /**
     * DELETE /api/vm/eventos/{evento}/imagenes/{imagen}
     */
    public function destroy(VmEvento $evento, Imagen $imagen): SymfonyResponse
    {
        $user = request()->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        if (!$this->userCanManageEvento($user->id, $evento)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para este evento.'], 403);
        }

        if (in_array($evento->estado, ['CERRADO', 'CANCELADO'], true)) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se pueden eliminar imágenes de un evento cerrado o cancelado.',
            ], 422);
        }

        if ($imagen->imageable_type !== VmEvento::class || (int) $imagen->imageable_id !== (int) $evento->id) {
            return response()->json(['ok' => false, 'message' => 'Imagen no pertenece al evento.'], 404);
        }

        if ($imagen->disk && $imagen->path && Storage::disk($imagen->disk)->exists($imagen->path)) {
            Storage::disk($imagen->disk)->delete($imagen->path);
        }

        $imagen->delete();

        return response()->noContent(); // 204
    }

    /**
     * Autoriza al usuario según el target del evento.
     * Usa EpScopeService (internamente Spatie permissions).
     */
    private function userCanManageEvento(int $userId, VmEvento $evento): bool
    {
        $type = strtolower($evento->targetable_type ?? '');
        $id   = (int) ($evento->targetable_id ?? 0);

        return match ($type) {
            'ep_sede'  => EpScopeService::userManagesEpSede($userId, $id),
            'sede'     => EpScopeService::userManagesSede($userId,  $id),
            'facultad' => EpScopeService::userManagesFacultad($userId, $id),
            default    => false,
        };
    }
}
