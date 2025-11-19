<?php
declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\Fluent\AssertableJson;

trait AuthHelpers
{
    /**
     * Inicia sesión por endpoint y devuelve encabezados Authorization Bearer.
     */
    protected function loginAs(string $username, string $password = 'UPeU2025'): array
    {
        $res = $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);

        $res->assertOk()->assertJson(fn (AssertableJson $json) =>
            $json->where('ok', true)
                 ->whereType('token', 'string')
                 ->has('user')
                 ->has('academico') // presente (null u objeto)
                 ->etc()            // permite otras claves si las hay
        );

        $token = $res->json('token');
        $this->assertNotSame('', (string) $token, 'El token no debe estar vacío.');

        return ['Authorization' => 'Bearer '.$token];
    }
}
