<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class AccountController extends Controller
{
    /**
     * GET /api/account/security
     *
     * Devuelve información de seguridad:
     *  - si el usuario está "online" (actividad reciente),
     *  - último login (si guardas last_login_* en users),
     *  - lista de sesiones activas (tipo Facebook).
     */
    public function security(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], 401);
        }

        // Consideramos "online" si tuvo actividad en los últimos 10 minutos
        $threshold = Carbon::now()->subMinutes(10)->timestamp;

        $isOnline = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', $threshold)
            ->exists();

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($s) {
                return [
                    'id'           => $s->id,
                    'ip'           => $s->ip_address,
                    'user_agent'   => $s->user_agent,
                    'last_active'  => Carbon::createFromTimestamp($s->last_activity)->toDateTimeString(),
                    'is_current'   => $s->id === session()->getId(),
                ];
            });

        return response()->json([
            'ok'       => true,
            'security' => [
                'is_online'          => $isOnline,
                'last_login_at'      => $user->last_login_at ?? null,
                'last_login_ip'      => $user->last_login_ip ?? null,
                'last_login_device'  => $user->last_login_user_agent ?? null,
                'sessions'           => $sessions,
            ],
        ]);
    }

    /**
     * DELETE /api/account/sessions/{id}
     *
     * Cierra una sesión específica del usuario (logout remoto).
     */
    public function destroySession(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], 401);
        }

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->delete();

        return response()->json([
            'ok'      => true,
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * DELETE /api/account/sessions
     *
     * Cierra todas las sesiones del usuario excepto la actual.
     */
    public function destroyOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], 401);
        }

        $currentId = session()->getId();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentId)
            ->delete();

        return response()->json([
            'ok'      => true,
            'message' => 'Otras sesiones cerradas correctamente.',
        ]);
    }

    /**
     * PUT /api/account/password
     *
     * Cambia la contraseña del usuario:
     *  - requiere contraseña actual,
     *  - nueva contraseña + confirmación,
     *  - opcionalmente cierra otras sesiones.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], 401);
        }

        $data = $request->validate([
            'current_password' => ['required'],
            'password'         => ['required', 'confirmed', 'min:8'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'ok'      => false,
                'message' => 'La contraseña actual no es correcta.',
            ], 422);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        // Opcional: cerrar todas las otras sesiones
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', session()->getId())
            ->delete();

        // Aquí podrías disparar un evento y mandar un correo de “tu contraseña ha sido cambiada”.

        return response()->json([
            'ok'      => true,
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    /**
     * PUT /api/account/profile
     *
     * Actualiza datos de perfil:
     *  - Siempre puede editar: first_name, last_name, profile_photo, celular, email personal.
     *  - Solo se pueden setear si están vacíos: doc_tipo, doc_numero, pais, fecha_nacimiento, religion.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado.',
            ], 401);
        }

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name'  => ['sometimes', 'string', 'max:255'],
            'profile_photo' => ['sometimes', 'nullable', 'string', 'max:255'], // aquí puedes cambiar a manejo de archivo si quieres
            'celular'    => ['sometimes', 'nullable', 'string', 'max:20'],
            'email'      => [
                'sometimes',
                'nullable',
                'email',
                Rule::unique('users', 'email')->ignore($user->id),
            ],

            // Solo permitir setear si están null en BD
            'doc_tipo'   => ['sometimes', 'in:DNI,CE,PASAPORTE,OTRO'],
            'doc_numero' => ['sometimes', 'string', 'max:50'],
            'pais'       => ['sometimes', 'string', 'size:2'],
            'fecha_nacimiento' => ['sometimes', 'date'],
            'religion'   => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Campos siempre editables
        if (array_key_exists('first_name', $data)) {
            $user->first_name = $data['first_name'];
        }
        if (array_key_exists('last_name', $data)) {
            $user->last_name = $data['last_name'];
        }
        if (array_key_exists('profile_photo', $data)) {
            $user->profile_photo = $data['profile_photo'];
        }
        if (array_key_exists('celular', $data)) {
            $user->celular = $data['celular'];
        }
        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }

        // Campos que solo se permiten si están null actualmente
        foreach (['doc_tipo', 'doc_numero', 'pais', 'fecha_nacimiento', 'religion'] as $field) {
            if (array_key_exists($field, $data) && is_null($user->{$field})) {
                $user->{$field} = $data[$field];
            }
        }

        $user->save();

        return response()->json([
            'ok'   => true,
            'user' => new UserDetailResource($user->fresh()),
        ]);
    }
}
