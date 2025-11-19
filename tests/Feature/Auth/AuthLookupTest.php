<?php
declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Feature\ApiTestCase;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Test;

final class AuthLookupTest extends ApiTestCase
{
    #[Test]
    public function lookup_admin_devuelve_academico_null(): void
    {
        $res = $this->postJson('/api/auth/lookup', ['username' => 'upeu.admin']);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)
                     ->has('user', fn (AssertableJson $user) =>
                         $user->where('username', 'upeu.admin')
                              ->hasAll([
                                  'id','first_name','last_name','full_name',
                                  'roles','rol_principal','permissions',
                              ])->etc()
                     )
                     ->where('academico', null)
                     ->etc()
            );
    }

    #[Test]
    public function lookup_estudiante_devuelve_academico_con_ep_y_sede(): void
    {
        $res = $this->postJson('/api/auth/lookup', ['username' => 'upeu.jorge']);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)
                     ->has('user', fn ($user) => $user->where('username', 'upeu.jorge')->etc())
                     ->has('academico', fn (AssertableJson $a) =>
                         $a->hasAll(['expediente_id','escuela_profesional','sede'])
                     )
                     ->etc()
            );
    }

    #[Test]
    public function lookup_username_inexistente_devuelve_404(): void
    {
        $res = $this->postJson('/api/auth/lookup', ['username' => 'no.existe']);
        $res->assertNotFound()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', false)
                     ->has('message')
                     ->etc()
            );
    }
}
