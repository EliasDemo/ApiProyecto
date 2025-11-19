<?php

namespace App\Http\Controllers\Api\EpSede;

use App\Http\Controllers\Controller;
use App\Services\Auth\EpScopeService;
use App\Models\User;
use App\Models\EpSede;
use Illuminate\Http\Request;

class EpSedeStaffContextController extends Controller
{
    public function context(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        // EP-Sedes donde el usuario tiene pertenencia activa con permiso
        $managedIds = EpScopeService::epSedesIdsManagedBy($userId);

        // EP-Sede por defecto: mismos criterios de Angular
        $defaultEpSede = count($managedIds) ? $managedIds[0] : null;

        // Modo del panel
        $panelMode = $user->can('ep.manage.ep_sede')
            ? 'ADMIN'
            : ($user->can('ep.manage.sede') ? 'COORDINADOR' : 'LIMITED');

        // Lista completa de EP-Sedes (id + label)
        $epSedes = EpSede::whereIn('id', $managedIds)
            ->get(['id', 'label'])
            ->map(fn($x) => [
                'id' => $x->id,
                'label' => $x->label,
            ])
            ->values();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->all(),
            ],

            'panel_mode' => $panelMode,
            'ep_sede_id' => $defaultEpSede,

            // Lista sencilla
            'ep_sede_ids' => $managedIds,

            // Igual a Angular/Flutter
            'ep_sedes_managed_ids' => $managedIds,

            // Permisos del panel (fuera de roles)
            'permissions' => [
                'manage_coordinador' => $user->can('ep.manage.ep_sede'),
                'manage_encargado' => $user->can('ep.manage.ep_sede') || $user->can('ep.manage.sede'),
            ],

            // Flags individuales
            'can_manage_coordinador' => $user->can('ep.manage.ep_sede'),
            'can_manage_encargado' => $user->can('ep.manage.ep_sede') || $user->can('ep.manage.sede'),
            'is_admin' => $user->can('ep.manage.ep_sede'),

            // Objetos EP-Sede usados por Flutter
            'ep_sedes' => $epSedes,
        ]);
    }
}
