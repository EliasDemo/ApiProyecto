<?php

namespace App\Services\EpSede;

use App\Models\User;
use App\Models\EpSede;
use App\Models\ExpedienteAcademico;
use App\Models\EpSedeStaffHistorial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StaffAssignmentService
{
    protected function shouldTouchUserStatus(): bool
    {
        return (bool) config('ep_staff.touch_user_status', true);
    }

    protected function setUserStatus(User $u, string $status): void
    {
        if (!$this->shouldTouchUserStatus()) {
            return;
        }

        if ($u->status !== $status) {
            $u->status = $status;
            $u->save();
        }
    }

    protected function guardSuspended(User $u): void
    {
        if (config('ep_staff.block_suspended_users', true) && $u->status === 'suspended') {
            abort(422, 'No se puede asignar un usuario con status "suspended".');
        }
    }

    protected function maxCoordinadorGuard(int $userId): void
    {
        $max = config('ep_staff.max_ep_coordinador');
        if (!$max) {
            return;
        }

        $count = ExpedienteAcademico::where('rol', 'COORDINADOR')
            ->where('estado', 'ACTIVO')
            ->where('user_id', $userId)
            ->count();

        if ($count >= $max) {
            abort(422, "Este usuario ya es COORDINADOR activo en {$count} EP_SEDE (máximo {$max}).");
        }
    }

    protected function cooldownGuard(int $epSedeId, int $userId, string $role): void
    {
        $days = (int) config('ep_staff.cooldown_days', 0);
        if ($days <= 0) {
            return;
        }

        $last = EpSedeStaffHistorial::where('ep_sede_id', $epSedeId)
            ->where('user_id', $userId)
            ->where('role', $role)
            ->whereIn('evento', ['UNASSIGN', 'AUTO_END'])
            ->latest('id')
            ->first();

        if ($last && $last->hasta) {
            $diff = Carbon::parse($last->hasta)->diffInDays(Carbon::today());
            if ($diff < $days) {
                abort(
                    422,
                    "No puede reincorporarse aún. Cooldown de {$days} días (faltan " . ($days - $diff) . ")."
                );
            }
        }
    }

    /**
     * 🔧 CORREGIDO: registra historial con nombres de columna reales (snake_case).
     */
    protected function log(
        int $epSedeId,
        int $userId,
        string $role,
        string $evento,
        ?string $desde,
        ?string $hasta,
        ?int $actorId,
        ?string $motivo
    ): void {
        EpSedeStaffHistorial::create([
            'ep_sede_id' => $epSedeId,
            'user_id'    => $userId,
            'role'       => $role,
            'evento'     => $evento,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'actor_id'   => $actorId,
            'motivo'     => $motivo,
        ]);
    }

    public function current(int $epSedeId): array
    {
        $rows = ExpedienteAcademico::query()
            ->with(['user:id,first_name,last_name,status'])
            ->where('ep_sede_id', $epSedeId)
            ->whereIn('rol', ['COORDINADOR', 'ENCARGADO'])
            ->where('estado', 'ACTIVO')
            ->get();

        $out = ['COORDINADOR' => null, 'ENCARGADO' => null];

        foreach ($rows as $r) {
            $out[$r->rol] = [
                'user_id'       => $r->user_id,
                // 🔧 CORREGIDO: usar datos del usuario relacionado
                'user'          => $r->user ? "{$r->user->last_name} {$r->user->first_name}" : null,
                'status'        => $r->user?->status,
                'rol'           => $r->rol,
                'vigente_desde' => $r->vigente_desde,
            ];
        }

        return $out;
    }

    public function assign(
        int $epSedeId,
        string $role,
        int $newUserId,
        ?string $vigenteDesde,
        bool $exclusive = true,
        ?int $actorId = null,
        ?string $motivo = null
    ): array {
        $role = strtoupper($role);
        if (!in_array($role, ['COORDINADOR', 'ENCARGADO'], true)) {
            abort(422, 'Rol inválido');
        }

        $otherRole = $role === 'COORDINADOR' ? 'ENCARGADO' : 'COORDINADOR';

        $ep    = EpSede::findOrFail($epSedeId);
        $now   = Carbon::today();
        $desde = $vigenteDesde ? Carbon::parse($vigenteDesde) : $now;

        $newUser = User::findOrFail($newUserId);
        $this->guardSuspended($newUser);

        if ($role === 'COORDINADOR') {
            $this->maxCoordinadorGuard($newUserId);
        }

        $this->cooldownGuard($epSedeId, $newUserId, $role);

        return DB::transaction(function () use (
            $epSedeId,
            $role,
            $otherRole,
            $newUser,
            $desde,
            $now,
            $exclusive,
            $actorId,
            $motivo
        ) {
            // Titular actual (lock)
            $current = ExpedienteAcademico::query()
                ->where('ep_sede_id', $epSedeId)
                ->where('rol', $role)
                ->where('estado', 'ACTIVO')
                ->lockForUpdate()
                ->first();

            // Si hay otro titular distinto -> suspenderlo
            if ($current && $current->user_id !== $newUser->id) {
                $prevUser = User::find($current->user_id);

                if ($prevUser && method_exists($prevUser, 'removeRole')) {
                    $prevUser->removeRole($role);
                    $this->setUserStatus(
                        $prevUser,
                        config('ep_staff.status_on_unassign', 'view_only')
                    );
                }

                $current->estado        = 'SUSPENDIDO';
                $current->vigente_hasta = $now->toDateString();
                $current->save();

                $this->log(
                    $epSedeId,
                    $current->user_id,
                    $role,
                    'UNASSIGN',
                    $current->vigente_desde,
                    $current->vigente_hasta,
                    $actorId,
                    $motivo
                );
            }

            // Exclusividad: el nuevo no puede mantener el otro cargo activo
            if ($exclusive) {
                $other = ExpedienteAcademico::query()
                    ->where('ep_sede_id', $epSedeId)
                    ->where('rol', $otherRole)
                    ->where('estado', 'ACTIVO')
                    ->where('user_id', $newUser->id)
                    ->lockForUpdate()
                    ->first();

                if ($other) {
                    if (method_exists($newUser, 'removeRole')) {
                        $newUser->removeRole($otherRole);
                    }

                    $other->estado        = 'SUSPENDIDO';
                    $other->vigente_hasta = $now->toDateString();
                    $other->save();

                    $this->log(
                        $epSedeId,
                        $newUser->id,
                        $otherRole,
                        'UNASSIGN',
                        $other->vigente_desde,
                        $other->vigente_hasta,
                        $actorId,
                        'Exclusividad aplicada'
                    );
                }
            }

            // Activar/crear expediente del nuevo titular
            $exp = ExpedienteAcademico::firstOrNew([
                'user_id'    => $newUser->id,
                'ep_sede_id' => $epSedeId,
            ]);

            $exp->rol           = $role;
            $exp->estado        = 'ACTIVO';
            $exp->vigente_desde = $desde->toDateString();
            $exp->vigente_hasta = null;
            $exp->save();

            // Rol Spatie + estado del user
            if (method_exists($newUser, 'assignRole')) {
                $newUser->assignRole($role);
            }

            $this->setUserStatus($newUser, config('ep_staff.status_on_assign', 'active'));

            $this->log(
                $epSedeId,
                $newUser->id,
                $role,
                'ASSIGN',
                $exp->vigente_desde,
                null,
                $actorId,
                $motivo
            );

            return [
                'ep_sede_id' => $epSedeId,
                'assigned'   => [
                    'user_id'       => $newUser->id,
                    'user'          => "{$newUser->last_name} {$newUser->first_name}",
                    'role'          => $role,
                    'vigente_desde' => $exp->vigente_desde,
                ],
                'previous'   => $current ? [
                    'user_id'       => $current->user_id,
                    'role'          => $role,
                    'vigente_hasta' => $now->toDateString(),
                ] : null,
            ];
        });
    }

    public function unassign(
        int $epSedeId,
        string $role,
        ?int $actorId = null,
        ?string $motivo = null
    ): array {
        $role = strtoupper($role);
        if (!in_array($role, ['COORDINADOR', 'ENCARGADO'], true)) {
            abort(422, 'Rol inválido');
        }

        return DB::transaction(function () use ($epSedeId, $role, $actorId, $motivo) {
            $current = ExpedienteAcademico::query()
                ->where('ep_sede_id', $epSedeId)
                ->where('rol', $role)
                ->where('estado', 'ACTIVO')
                ->lockForUpdate()
                ->first();

            if (!$current) {
                return ['unassigned' => null];
            }

            $u = User::find($current->user_id);

            if ($u && method_exists($u, 'removeRole')) {
                $u->removeRole($role);
                $this->setUserStatus(
                    $u,
                    config('ep_staff.status_on_unassign', 'view_only')
                );
            }

            $current->estado        = 'SUSPENDIDO';
            $current->vigente_hasta = Carbon::today()->toDateString();
            $current->save();

            $this->log(
                $epSedeId,
                $current->user_id,
                $role,
                'UNASSIGN',
                $current->vigente_desde,
                $current->vigente_hasta,
                $actorId,
                $motivo
            );

            return [
                'unassigned' => [
                    'user_id'       => $u?->id,
                    'role'          => $role,
                    'vigente_hasta' => $current->vigente_hasta,
                ],
            ];
        });
    }

    public function reinstate(
        int $epSedeId,
        string $role,
        int $userId,
        ?string $vigenteDesde,
        bool $exclusive = true,
        ?int $actorId = null,
        ?string $motivo = null
    ): array {
        $out = $this->assign(
            $epSedeId,
            $role,
            $userId,
            $vigenteDesde,
            $exclusive,
            $actorId,
            $motivo
        );

        // Marca explícita REINSTATE en historial (además del ASSIGN)
        $this->log(
            $epSedeId,
            $userId,
            strtoupper($role),
            'REINSTATE',
            $out['assigned']['vigente_desde'],
            null,
            $actorId,
            $motivo
        );

        return $out;
    }

    public function delegate(
        int $epSedeId,
        string $role,
        int $userId,
        string $desde,
        string $hasta,
        ?int $actorId = null,
        ?string $motivo = null
    ): array {
        if (strtoupper($role) !== 'ENCARGADO') {
            abort(422, 'Solo se delega ENCARGADO como interino.');
        }

        $out = $this->assign(
            $epSedeId,
            $role,
            $userId,
            $desde,
            true,
            $actorId,
            $motivo
        );

        $exp = ExpedienteAcademico::where('ep_sede_id', $epSedeId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $exp->vigente_hasta = Carbon::parse($hasta)->toDateString();
        $exp->save();

        $this->log(
            $epSedeId,
            $userId,
            'ENCARGADO',
            'DELEGATE',
            $desde,
            $hasta,
            $actorId,
            $motivo
        );

        return $out;
    }

    public function history(int $epSedeId): array
    {
        return EpSedeStaffHistorial::where('ep_sede_id', $epSedeId)
            ->orderBy('id', 'desc')
            ->limit(200)
            ->get()
            ->toArray();
    }
}
