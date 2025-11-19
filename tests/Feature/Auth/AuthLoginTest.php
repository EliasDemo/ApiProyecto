<?php
declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Feature\ApiTestCase;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Test;

final class AuthLoginTest extends ApiTestCase
{
    #[Test]
    public function login_exitoso_retorna_token_para_admin(): void
    {
        $res = $this->postJson('/api/auth/login', [
            'username' => 'upeu.admin',
            'password' => 'UPeU2025',
        ]);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)
                     ->whereType('token', 'string')
                     ->has('user', fn ($user) => $user->where('username','upeu.admin')->etc())
                     ->where('academico', null) // admin no tiene expediente
                     ->etc()
            );

        $this->assertNotSame('', (string) $res->json('token'));
    }

    #[Test]
    public function login_con_password_invalido_retorna_422(): void
    {
        $res = $this->postJson('/api/auth/login', [
            'username' => 'upeu.admin',
            'password' => 'incorrecta',
        ]);

        $res->assertUnprocessable()
            ->assertJson(fn (AssertableJson $json) =>
                $json->has('message')
                     ->has('errors.credentials')
                     ->etc()
            );
    }

    #[Test]
    public function login_con_usuario_inexistente_retorna_422(): void
    {
        $res = $this->postJson('/api/auth/login', [
            'username' => 'no.existe',
            'password' => 'X',
        ]);

        $res->assertUnprocessable()
            ->assertJson(fn (AssertableJson $json) =>
                $json->has('message')
                     ->has('errors.credentials')
                     ->etc()
            );
    }
}
