<?php
declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Test;

final class AuthLogoutTest extends ApiTestCase
{
    use AuthHelpers;

    #[Test]
    public function logout_con_token_valido_revoca_y_responde_200(): void
    {
        $headers = $this->loginAs('upeu.admin');

        $res = $this->postJson('/api/auth/logout', [], $headers);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)
                     ->has('message')
                     ->etc()
            );
    }

    #[Test]
    public function logout_sin_token_retorna_401(): void
    {
        $res = $this->postJson('/api/auth/logout');
        $res->assertUnauthorized();
    }
}
