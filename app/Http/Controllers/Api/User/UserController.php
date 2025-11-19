<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserDetailResource;
use App\Models\ExpedienteAcademico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    /**
     * GET /api/users/me
     * Perfil completo del autenticado (permisos, expedientes, sede, escuela, facultad, universidad, etc.).
     */
    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $this->cargarRelaciones($user);

        return response()->json([
            'ok'   => true,
            'user' => new UserDetailResource($user),
        ]);
    }

    /**
     * PUT /api/users/me
     * Actualiza datos de perfil permitidos:
     *  - first_name, last_name, email
     *  - profile_photo (archivo), celular, religion
     *  - correo_institucional (sobre un expediente del propio usuario)
     */
    public function updateMe(Request $request): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'first_name'           => ['sometimes', 'string', 'max:255'],
            'last_name'            => ['sometimes', 'string', 'max:255'],
            'email'                => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'celular'              => ['sometimes', 'nullable', 'string', 'max:20'],
            'religion'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile_photo'        => ['sometimes', 'nullable', 'image', 'max:2048'],

            // correo institucional en expediente
            'correo_institucional' => ['sometimes', 'nullable', 'email', 'max:255'],
            'expediente_id'        => ['sometimes', 'required_with:correo_institucional', 'integer', 'exists:expedientes_academicos,id'],
        ]);

        // Actualizar datos básicos permitidos
        foreach (['first_name', 'last_name', 'email', 'celular', 'religion'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }

        // Foto de perfil (archivo)
        if ($request->hasFile('profile_photo')) {
            $file = $request->file('profile_photo');

            if ($user->profile_photo && Storage::disk('public')->exists($user->profile_photo)) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            $path = $file->store('profile_photos', 'public');
            $user->profile_photo = $path;
        }

        $user->save();

        // Actualizar correo_institucional en expediente del propio user
        if (array_key_exists('correo_institucional', $data)) {
            $expQuery = ExpedienteAcademico::where('user_id', $user->id);

            if (isset($data['expediente_id'])) {
                $expQuery->where('id', $data['expediente_id']);
            }

            $exp = $expQuery->first();

            if ($exp) {
                $exp->correo_institucional = $data['correo_institucional'];
                $exp->save();
            }
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Perfil actualizado correctamente.',
            'user'    => [
                'id'            => $user->id,
                'username'      => $user->username,
                'first_name'    => $user->first_name,
                'last_name'     => $user->last_name,
                'email'         => $user->email,
                'profile_photo' => $user->profile_photo,
                'celular'       => $user->celular,
                'religion'      => $user->religion,
            ],
        ]);
    }

    /**
     * PUT /api/users/me/password
     * Cambiar contraseña del propio usuario.
     */
    public function updateMyPassword(Request $request): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'current_password' => ['required'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'ok'      => false,
                'message' => 'La contraseña actual no es correcta.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->password = Hash::make($data['password']);
        $user->failed_login_attempts = 0;
        $user->login_blocked_until   = null;
        $user->save();

        return response()->json([
            'ok'      => true,
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    /**
     * GET /api/users/me/sessions
     * Lista de sesiones activas del usuario autenticado.
     */
    public function sessions(Request $request): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $currentSessionId = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get();

        $items = $sessions->map(function ($s) use ($currentSessionId) {
            return [
                'id'            => $s->id,
                'ip_address'    => $s->ip_address,
                'user_agent'    => $s->user_agent,
                'last_activity' => Carbon::createFromTimestamp($s->last_activity)->toIso8601String(),
                'is_current'    => $s->id === $currentSessionId,
            ];
        })->values();

        return response()->json([
            'ok'       => true,
            'sessions' => $items,
        ]);
    }

    /**
     * DELETE /api/users/me/sessions/{id}
     * Cerrar una sesión del propio usuario. Si es la actual, hace logout.
     */
    public function destroySession(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $currentSessionId = $request->session()->getId();

        $session = DB::table('sessions')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$session) {
            return response()->json([
                'ok'      => false,
                'message' => 'Sesión no encontrada.',
            ], Response::HTTP_NOT_FOUND);
        }

        DB::table('sessions')->where('id', $id)->delete();

        if ($id === $currentSessionId) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * GET /api/users/by-username/{username}
     * Busca por username y devuelve toda la información vinculada.
     *
     * Regla de permiso:
     *  - Si consulta su propio username -> NO requiere permiso extra.
     *  - Si consulta el de otra persona -> requiere 'user.view.any'.
     */
    public function showByUsername(string $username): JsonResponse
    {
        $actor = request()->user();

        if (!$actor) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($actor->username !== $username && !$actor->can('user.view.any')) {
            return response()->json([
                'ok'      => false,
                'message' => 'NO_AUTORIZADO',
            ], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Models\User $user */
        $user = User::query()
            ->where('username', $username)
            ->firstOrFail();

        $this->cargarRelaciones($user);

        return response()->json([
            'ok'   => true,
            'user' => new UserDetailResource($user),
        ]);
    }

    /**
     * Eager load de todo lo necesario para evitar N+1.
     */
    private function cargarRelaciones(User $user): void
    {
        $user->load([
            'expedientesAcademicos' => function ($q) {
                $q->with([
                    'epSede' => function ($qq) {
                        $qq->with([
                            'sede:id,nombre,es_principal,esta_suspendida',
                            'escuelaProfesional' => function ($qp) {
                                $qp->select('id', 'facultad_id', 'codigo', 'nombre')
                                   ->with([
                                       'facultad' => function ($qf) {
                                           $qf->select('id', 'universidad_id', 'codigo', 'nombre')
                                              ->with([
                                                  'universidad:id,codigo,nombre,tipo_gestion,estado_licenciamiento',
                                              ]);
                                       },
                                   ]);
                            },
                        ]);
                    },
                    'matriculas' => function ($qm) {
                        $qm->with([
                            'periodo:id,codigo,anio,ciclo,estado,es_actual,fecha_inicio,fecha_fin',
                        ])->orderByDesc('id');
                    },
                ])->orderByDesc('id');
            },
        ]);
    }
}
