<?php

namespace App\Services\Auth;

use App\Models\ExpedienteAcademico;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class EpScopeService
{
    // Permisos (Spatie)
    public const PERM_MANAGE_EP_SEDE   = 'ep.manage.ep_sede';
    public const PERM_MANAGE_SEDE      = 'ep.manage.sede';
    public const PERM_MANAGE_FACULTAD  = 'ep.manage.facultad';
    public const PERM_VIEW_EXPEDIENTE  = 'ep.view.expediente';

    /** Determina el nombre de la columna de usuario: user_id o usuario_id */
    protected static function userIdColumn(): string
    {
        return Schema::hasColumn('expedientes_academicos', 'user_id') ? 'user_id' : 'usuario_id';
    }

    /** Carga el usuario (necesario para ->can()) */
    protected static function user(int $userId): ?User
    {
        return User::find($userId);
    }

    public static function userManagesEpSede(int $userId, int $epSedeId): bool
    {
        $user = self::user($userId);
        if (!$user || !$user->can(self::PERM_MANAGE_EP_SEDE)) {
            return false;
        }

        $col = self::userIdColumn();
        return ExpedienteAcademico::query()
            ->where($col, $user->id)
            ->where('ep_sede_id', $epSedeId)
            ->where('estado', 'ACTIVO')
            ->exists();
    }

    public static function userManagesSede(int $userId, int $sedeId): bool
    {
        $user = self::user($userId);
        if (!$user || !$user->can(self::PERM_MANAGE_SEDE)) {
            return false;
        }

        $col = self::userIdColumn();
        return ExpedienteAcademico::query()
            ->where($col, $user->id)
            ->where('estado', 'ACTIVO')
            ->whereHas('epSede', fn($q) => $q->where('sede_id', $sedeId))
            ->exists();
    }

    public static function userManagesFacultad(int $userId, int $facultadId): bool
    {
        $user = self::user($userId);
        if (!$user || !$user->can(self::PERM_MANAGE_FACULTAD)) {
            return false;
        }

        $col = self::userIdColumn();
        return ExpedienteAcademico::query()
            ->where($col, $user->id)
            ->where('estado', 'ACTIVO')
            ->whereHas('epSede.escuelaProfesional', fn($q) => $q->where('facultad_id', $facultadId))
            ->exists();
    }

    /** EP-Sedes que el usuario puede gestionar (según permiso + pertenencia activa) */
    public static function epSedesIdsManagedBy(int $userId): array
    {
        $user = self::user($userId);
        if (!$user || !$user->can(self::PERM_MANAGE_EP_SEDE)) {
            return [];
        }

        $col = self::userIdColumn();
        return ExpedienteAcademico::query()
            ->where($col, $user->id)
            ->where('estado', 'ACTIVO')
            ->pluck('ep_sede_id')
            ->unique()
            ->values()
            ->all();
    }

    /** Devuelve el ID del expediente activo del usuario (si tiene permiso de ver) */
    public static function expedienteId(int $userId): ?int
    {
        $user = self::user($userId);
        if (!$user || !$user->can(self::PERM_VIEW_EXPEDIENTE)) {
            return null;
        }

        $col = self::userIdColumn();
        $q = ExpedienteAcademico::query()
            ->select('id')
            ->where('estado', 'ACTIVO')
            ->where($col, $user->id);

        return optional($q->latest('id')->first())->id;
    }

    /** Pertenencia simple (no exige permiso de gestión) */
    public static function userBelongsToEpSede(int $userId, int $epSedeId): bool
    {
        $user = self::user($userId);
        if (!$user) {
            return false;
        }

        $col = self::userIdColumn();
        return ExpedienteAcademico::query()
            ->where('ep_sede_id', $epSedeId)
            ->where('estado', 'ACTIVO')
            ->where($col, $user->id)
            ->exists();
    }

    /** 🆕 EP-SEDE activa del usuario (la que usaremos para los eventos) */
    public static function epSedeIdForUser(int $userId): ?int
    {
        $user = self::user($userId);
        if (!$user) {
            return null;
        }

        $col = self::userIdColumn();

        $exp = ExpedienteAcademico::query()
            ->where($col, $user->id)
            ->where('estado', 'ACTIVO')
            ->latest('id')
            ->first();

        return $exp?->ep_sede_id;
    }
}
