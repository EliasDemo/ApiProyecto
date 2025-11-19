<?php

namespace App\Http\Controllers\Api\EpSede;

use App\Http\Controllers\Controller;
use App\Http\Requests\EpSede\AssignEpSedeStaffRequest;
use App\Http\Requests\EpSede\DelegateEpSedeStaffRequest;
use App\Http\Requests\EpSede\ReinstateEpSedeStaffRequest;
use App\Http\Requests\EpSede\UnassignEpSedeStaffRequest;
use App\Models\User;
use App\Models\ExpedienteAcademico;
use App\Models\EpSede;
use App\Services\Auth\EpScopeService;
use App\Services\EpSede\StaffAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

class EpSedeStaffController extends Controller
{
    /**
     * Autoriza acciones sobre staff según:
     * - rol objetivo (COORDINADOR / ENCARGADO)
     * - rol/permisos del actor (ADMIN, COORDINADOR, etc.)
     * - EP-Sede objetivo
     */
    protected function authorizeStaffAction(Request $request, int $epSedeId, string $role): void
    {
        $user = $request->user();
        $role = strtoupper($role);

        if (!$user) {
            abort(Response::HTTP_FORBIDDEN, 'No autenticado.');
        }

        $isAdmin = $user->hasRole('ADMINISTRADOR');

        switch ($role) {
            case 'COORDINADOR':
                // Admin siempre puede, aunque no tenga el permiso explícito
                if (
                    !$isAdmin &&
                    !$user->can('ep.staff.manage.coordinador')
                ) {
                    abort(Response::HTTP_FORBIDDEN, 'No autorizado para gestionar coordinadores.');
                }

                if (
                    !$isAdmin &&
                    !EpScopeService::userManagesEpSede((int) $user->id, $epSedeId)
                ) {
                    abort(Response::HTTP_FORBIDDEN, 'No autorizado para esta EP-Sede.');
                }
                break;

            case 'ENCARGADO':
                // Admin siempre puede, aunque no tenga el permiso explícito
                if (
                    !$isAdmin &&
                    !$user->can('ep.staff.manage.encargado')
                ) {
                    abort(Response::HTTP_FORBIDDEN, 'No autorizado para gestionar encargados.');
                }

                if (
                    !$isAdmin &&
                    !EpScopeService::userBelongsToEpSede((int) $user->id, $epSedeId) &&
                    !EpScopeService::userManagesEpSede((int) $user->id, $epSedeId)
                ) {
                    abort(Response::HTTP_FORBIDDEN, 'No autorizado para esta EP-Sede.');
                }
                break;

            default:
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Rol no soportado.');
        }
    }

    /**
     * Contexto general del panel de staff para el usuario autenticado.
     *
     * GET /api/ep-sedes/staff/context
     *
     * Devuelve:
     * - user.{id, username, first_name, last_name, email, roles[]}
     * - panel_mode: ADMIN | COORDINADOR | LIMITED
     * - ep_sede_id: EP-Sede “por defecto” (si aplica)
     * - ep_sede_ids: EP-Sedes dentro de su alcance (para el front)
     * - ep_sedes_managed_ids: EP-Sedes de las que es responsable según EpScopeService
     * - permissions.manage_coordinador / manage_encargado
     * - can_manage_coordinador / can_manage_encargado (flags planos)
     * - is_admin
     * - ep_sedes: listado [{ id, label }] para poblar el combo en el front
     */
    public function context(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'No autenticado.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $userId = (int) $user->id;
        $roles = method_exists($user, 'roles')
            ? $user->roles->pluck('name')->all()
            : [];

        // EP-Sedes que el usuario "gestiona" (puede venir como array o Collection)
        $managedEpSedeIds = EpScopeService::epSedesIdsManagedBy($userId);
        if ($managedEpSedeIds instanceof \Illuminate\Support\Collection) {
            $managedEpSedeIds = $managedEpSedeIds->all();
        }

        // Normalizamos a array<int>
        $managedEpSedeIds = array_values(
            array_map('intval', (array) $managedEpSedeIds)
        );

        $isAdmin = $user->hasRole('ADMINISTRADOR');

        // Admin siempre puede gestionar ambos
        $canManageCoord = $isAdmin || $user->can('ep.staff.manage.coordinador');
        $canManageEnc   = $isAdmin || $user->can('ep.staff.manage.encargado');

        $panelMode      = 'LIMITED';
        $defaultEpSedeId = null;

        if ($isAdmin) {
            // Admin global → modo ADMIN (puede moverse por cualquier EP-Sede)
            $panelMode = 'ADMIN';
        } else {
            // Coordinador típico: sólo encargados y una sola EP-Sede
            if ($canManageEnc && !$canManageCoord && \count($managedEpSedeIds) === 1) {
                $panelMode      = 'COORDINADOR';
                $defaultEpSedeId = $managedEpSedeIds[0];
            } elseif ($canManageEnc || $canManageCoord) {
                // Staff con permisos sobre varias EP-Sede → panel se comporta como ADMIN
                $panelMode = 'ADMIN';
            }
        }

        // Si aún no se definió EP-Sede por defecto y sólo gestiona una, la usamos
        if ($defaultEpSedeId === null && \count($managedEpSedeIds) === 1) {
            $defaultEpSedeId = $managedEpSedeIds[0];
        }

        // ──────────────────────────────────────────────
        // EP-Sedes visibles en el panel
        // - Admin: todas
        // - No admin: solo las que EpScopeService indica
        //   (ep_sede.id es la PK de la tabla ep_sede)
        // ──────────────────────────────────────────────

        $epSedesQuery = EpSede::query()
            ->with(['sede', 'escuelaProfesional']); // 👈 cargamos sede + EP

        if (!$isAdmin) {
            $epSedesQuery->whereIn('id', $managedEpSedeIds);
        }

        $epSedesCollection = $epSedesQuery
            ->orderBy('id')
            ->get();

        $epSedes = $epSedesCollection
            ->map(function (EpSede $ep) {
                $sede     = $ep->sede;                  // modelo Sede
                $escuela  = $ep->escuelaProfesional;    // modelo EscuelaProfesional

                $sedeNombre = $sede?->nombre ?? null;
                $epCodigo   = $escuela?->codigo ?? null;
                $epNombre   = $escuela?->nombre ?? null;

                // Queremos: "Campus Juliaca – ISI Ingeniería de Sistemas"
                $epTexto = trim(implode(' ', array_filter([$epCodigo, $epNombre])));

                $parts = [];

                if ($sedeNombre) {
                    $parts[] = $sedeNombre;
                }

                if ($epTexto !== '') {
                    // Separador bonito entre sede y EP
                    $parts[] = '– ' . $epTexto;
                }

                $label = trim(implode(' ', $parts));

                if ($label === '') {
                    $label = 'EP-Sede #' . $ep->id;
                }

                return [
                    'id'    => (int) $ep->id,
                    'label' => $label, // 👈 lo que lee el front para el <select>
                ];
            })
            ->values()
            ->all();

        // Para el front, ep_sede_ids representa el universo de EP-Sedes que puede usar:
        // - Admin: todas las devueltas en ep_sedes
        // - No admin: las que EpScopeService marca como gestionadas
        $epSedeIdsForFront = $isAdmin
            ? $epSedesCollection->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $managedEpSedeIds;

        return response()->json([
            'user' => [
                'id'         => $userId,
                'username'   => $user->username ?? null,
                'first_name' => $user->first_name ?? null,
                'last_name'  => $user->last_name ?? null,
                'email'      => $user->email ?? null,
                'roles'      => $roles,
            ],

            'panel_mode' => $panelMode,         // ADMIN | COORDINADOR | LIMITED
            'ep_sede_id' => $defaultEpSedeId,   // null o EP-Sede fija

            'ep_sedes_managed_ids' => $managedEpSedeIds,   // según EpScopeService
            'ep_sede_ids'          => $epSedeIdsForFront,  // para el front (selector / navegación)

            // API nueva tipo "context v2"
            'permissions' => [
                'manage_coordinador' => $canManageCoord,
                'manage_encargado'   => $canManageEnc,
            ],

            // Flags sueltos para compatibilidad
            'can_manage_coordinador' => $canManageCoord,
            'can_manage_encargado'   => $canManageEnc,
            'is_admin'               => $isAdmin,

            // Lista para el <select> del front: [{ id, label }]
            'ep_sedes' => $epSedes,
        ], Response::HTTP_OK);
    }


    /**
     * Staff actual (coordinador/encargado) de la EP-Sede.
     * GET /api/ep-sedes/{epSedeId}/staff
     */
    public function current(Request $request, int $epSedeId, StaffAssignmentService $service)
    {
        $user   = $request->user();
        $userId = (int) optional($user)->id;

        if (
            !$user?->hasRole('ADMINISTRADOR') &&
            !EpScopeService::userManagesEpSede($userId, $epSedeId)
        ) {
            return response()->json(['message' => 'No autorizado.'], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'ep_sede_id' => $epSedeId,
            'staff'      => $service->current($epSedeId),
        ], Response::HTTP_OK);
    }

    /**
     * Historial de cambios de staff en la EP-Sede.
     * GET /api/ep-sedes/{epSedeId}/staff/history
     */
    public function history(Request $request, int $epSedeId, StaffAssignmentService $service)
    {
        $user   = $request->user();
        $userId = (int) optional($user)->id;

        if (
            !$user?->hasRole('ADMINISTRADOR') &&
            !EpScopeService::userManagesEpSede($userId, $epSedeId)
        ) {
            return response()->json(['message' => 'No autorizado.'], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'ep_sede_id' => $epSedeId,
            'history'    => $service->history($epSedeId),
        ], Response::HTTP_OK);
    }

    /**
     * Asignar un COORDINADOR / ENCARGADO existente (ya tiene User).
     * POST /api/ep-sedes/{epSedeId}/staff/assign
     */
    public function assign(AssignEpSedeStaffRequest $request, int $epSedeId, StaffAssignmentService $service)
    {
        $role = strtoupper((string) $request->string('role'));

        $this->authorizeStaffAction($request, $epSedeId, $role);

        $payload = $service->assign(
            epSedeId:      $epSedeId,
            role:          $role,
            newUserId:     (int) $request->integer('user_id'),
            vigenteDesde:  $request->input('vigente_desde'),
            exclusive:     $request->exclusive(),
            actorId:       $request->user()->id,
            motivo:        $request->input('motivo')
        );

        return response()->json($payload, Response::HTTP_OK);
    }

    /**
     * Desasignar COORDINADOR / ENCARGADO actual.
     * POST /api/ep-sedes/{epSedeId}/staff/unassign
     */
    public function unassign(UnassignEpSedeStaffRequest $request, int $epSedeId, StaffAssignmentService $service)
    {
        $role = strtoupper((string) $request->string('role'));

        $this->authorizeStaffAction($request, $epSedeId, $role);

        $payload = $service->unassign(
            epSedeId: $epSedeId,
            role:     $role,
            actorId:  $request->user()->id,
            motivo:   $request->input('motivo')
        );

        return response()->json($payload, Response::HTTP_OK);
    }

    /**
     * Reincorporar a un usuario como COORDINADOR / ENCARGADO.
     * POST /api/ep-sedes/{epSedeId}/staff/reinstate
     */
    public function reinstate(ReinstateEpSedeStaffRequest $request, int $epSedeId, StaffAssignmentService $service)
    {
        $role = strtoupper((string) $request->string('role'));

        $this->authorizeStaffAction($request, $epSedeId, $role);

        $payload = $service->reinstate(
            epSedeId:      $epSedeId,
            role:          $role,
            userId:        (int) $request->integer('user_id'),
            vigenteDesde:  $request->input('vigente_desde'),
            exclusive:     true,
            actorId:       $request->user()->id,
            motivo:        $request->input('motivo')
        );

        return response()->json($payload, Response::HTTP_OK);
    }

    /**
     * Delegar ENCARGADO interino con fecha fin.
     * POST /api/ep-sedes/{epSedeId}/staff/delegate
     */
    public function delegate(DelegateEpSedeStaffRequest $request, int $epSedeId, StaffAssignmentService $service)
    {
        $role = strtoupper((string) $request->string('role'));

        $this->authorizeStaffAction($request, $epSedeId, $role);

        $payload = $service->delegate(
            epSedeId: $epSedeId,
            role:     $role,
            userId:   (int) $request->integer('user_id'),
            desde:    (string) $request->string('desde'),
            hasta:    (string) $request->string('hasta'),
            actorId:  $request->user()->id,
            motivo:   $request->input('motivo')
        );

        return response()->json($payload, Response::HTTP_OK);
    }

    /**
     * 🔍 Buscar perfil por correo (user + expediente en esta EP-Sede).
     * GET /api/ep-sedes/{epSedeId}/staff/lookup?email=...
     */
    public function lookupByEmail(Request $request, int $epSedeId)
    {
        $user   = $request->user();
        $userId = (int) optional($user)->id;

        if (
            !$user?->hasRole('ADMINISTRADOR') &&
            !EpScopeService::userManagesEpSede($userId, $epSedeId) &&
            !EpScopeService::userBelongsToEpSede($userId, $epSedeId)
        ) {
            return response()->json(['message' => 'No autorizado.'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($data['email']));

        $userModel = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        $expediente = ExpedienteAcademico::query()
            ->where('ep_sede_id', $epSedeId)
            ->whereRaw('LOWER(correo_institucional) = ?', [$email])
            ->first();

        if ($expediente && !$userModel && method_exists($expediente, 'user')) {
            $userModel = $expediente->user;
        }

        return response()->json([
            'email' => $email,
            'user'  => $userModel ? [
                'id'         => $userModel->id,
                'username'   => $userModel->username,
                'first_name' => $userModel->first_name,
                'last_name'  => $userModel->last_name,
                'email'      => $userModel->email,
                'status'     => $userModel->status,
                'roles'      => method_exists($userModel, 'roles')
                    ? $userModel->roles->pluck('name')->all()
                    : [],
            ] : null,
            'expediente' => $expediente ? [
                'id'                   => $expediente->id,
                'ep_sede_id'           => $expediente->ep_sede_id,
                'estado'               => $expediente->estado,
                'rol'                  => $expediente->rol,
                'correo_institucional' => $expediente->correo_institucional,
                'codigo_estudiante'    => $expediente->codigo_estudiante,
                'vigente_desde'        => $expediente->vigente_desde,
                'vigente_hasta'        => $expediente->vigente_hasta,
            ] : null,
        ], Response::HTTP_OK);
    }

    /**
     * Crear un usuario (docente/staff) y asignarlo como COORDINADOR o ENCARGADO.
     *
     * POST /api/ep-sedes/{epSedeId}/staff/create-and-assign
     */
    public function createAndAssign(Request $request, int $epSedeId, StaffAssignmentService $service)
    {
        try {
            // 1) Validar
            $data = $request->validate([
                'role'                 => 'required|string', // COORDINADOR | ENCARGADO | ENCARGADO_*
                'username'             => 'required|string|unique:users,username',
                'first_name'           => 'required|string',
                'last_name'            => 'required|string',
                'email'                => 'nullable|email|unique:users,email',
                'doc_tipo'             => 'nullable|in:DNI,CE,PASAPORTE,OTRO',
                // obligatorio, se usará como contraseña inicial
                'doc_numero'           => 'required|string|min:8|max:20',
                'celular'              => 'nullable|string|max:20',
                'pais'                 => 'nullable|string|size:2',
                'vigente_desde'        => 'nullable|date',
                'motivo'               => 'nullable|string|max:255',
                'correo_institucional' => 'nullable|email',
            ]);

            $rawRole = strtoupper($data['role']);

            $assignRole = $rawRole === 'COORDINADOR'
                ? 'COORDINADOR'
                : 'ENCARGADO';

            // 2) Autorizar (según rol objetivo + EP-Sede)
            $this->authorizeStaffAction($request, $epSedeId, $assignRole);

            // 3) Crear usuario
            $user = new User();
            $user->username   = $data['username'];
            $user->first_name = $data['first_name'];
            $user->last_name  = $data['last_name'];
            $user->email      = $data['email'] ?? null;

            // Normalizamos el número de documento
            $rawDoc = trim($data['doc_numero']);

            // CONTRASEÑA INICIAL = NÚMERO DE DOCUMENTO (hasheado)
            $user->password   = bcrypt($rawDoc);

            $user->status     = 'active';
            $user->doc_tipo   = $data['doc_tipo'] ?? null;
            $user->doc_numero = $rawDoc;
            $user->celular    = $data['celular'] ?? null;
            $user->pais       = $data['pais'] ?? null;
            $user->save();

            // 3.b) Rol Spatie
            if (method_exists($user, 'assignRole')) {
                if (Role::where('name', $rawRole)->exists()) {
                    $user->assignRole($rawRole);
                } elseif (Role::where('name', $assignRole)->exists()) {
                    $user->assignRole($assignRole);
                }
            }

            // 4) Lógica de expedientes + historial
            $payload = $service->assign(
                epSedeId:      $epSedeId,
                role:          $assignRole,
                newUserId:     $user->id,
                vigenteDesde:  $data['vigente_desde'] ?? null,
                exclusive:     true,
                actorId:       $request->user()->id,
                motivo:        $data['motivo'] ?? null
            );

            // 4.b) Actualizar correo_institucional si lo mandaste
            if (!empty($data['correo_institucional'])) {
                ExpedienteAcademico::where('ep_sede_id', $epSedeId)
                    ->where('user_id', $user->id)
                    ->update([
                        'correo_institucional' => $data['correo_institucional'],
                    ]);
            }

            // 5) Respuesta
            return response()->json([
                'ep_sede_id'  => $epSedeId,
                'assign_role' => $assignRole,
                'raw_role'    => $rawRole,
                'user'        => [
                    'id'         => $user->id,
                    'username'   => $user->username,
                    'first_name' => $user->first_name,
                    'last_name'  => $user->last_name,
                    'email'      => $user->email,
                    'status'     => $user->status,
                ],
                'assignment'  => $payload,
            ], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            Log::error('Error en EpSedeStaffController@createAndAssign', [
                'ep_sede_id' => $epSedeId,
                'request'    => $request->all(),
                'user_id'    => optional($request->user())->id,
                'exception'  => $e,
            ]);

            return response()->json([
                'message' => 'Error interno al crear y asignar staff.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
