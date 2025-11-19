<?php
declare(strict_types=1);

namespace Tests\Feature\Users;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use Illuminate\Testing\Fluent\AssertableJson;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use PHPUnit\Framework\Attributes\Test;

final class UsersByUsernameTest extends ApiTestCase
{
    use AuthHelpers;

    #[Test]
    public function un_usuario_puede_consultarse_a_si_mismo(): void
    {
        $headers = $this->loginAs('upeu.jorge');

        $res = $this->getJson('/api/users/by-username/upeu.jorge', $headers);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)
                     ->has('user', fn ($u) => $u->where('username', 'upeu.jorge')->etc())
                     ->etc()
            );
    }

    #[Test]
    public function un_usuario_sin_permiso_no_puede_ver_a_otro_usuario(): void
    {
        $headers = $this->loginAs('upeu.jorge');

        $res = $this->getJson('/api/users/by-username/upeu.sofia', $headers);

        $res->assertForbidden();
    }

    #[Test]
    public function administrador_con_permiso_user_view_any_puede_ver_a_otros(): void
    {
        // Asegura permiso y relación con ADMINISTRADOR
        if (! Permission::where('name', 'user.view.any')->exists()) {
            Permission::create(['name' => 'user.view.any', 'guard_name' => 'web']);
        }
        $adminRole = Role::where('name', 'ADMINISTRADOR')->first();
        if ($adminRole && ! $adminRole->hasPermissionTo('user.view.any')) {
            $adminRole->givePermissionTo('user.view.any');
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $headers = $this->loginAs('upeu.admin');

        $res = $this->getJson('/api/users/by-username/upeu.sofia', $headers);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)
                     ->has('user', fn ($u) => $u->where('username', 'upeu.sofia')->etc())
                     ->etc()
            );
    }
}
