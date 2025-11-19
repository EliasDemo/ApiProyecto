<?php
declare(strict_types=1);

namespace Tests\Feature\Academico;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Test;

final class FacultadesAdminTest extends ApiTestCase
{
    use AuthHelpers;

    private function extractItems(array $payload): array
    {
        // Soporta { ok, data:[...] } o bien respuesta plana [...]
        return isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
    }

    #[Test]
    public function index_admin_lista_facultades_con_posible_include(): void
    {
        $headers = $this->loginAs('upeu.admin');

        $res = $this->getJson('/api/administrador/academico/facultades?include=escuelasProfesionales', $headers);
        $res->assertOk();

        $items = $this->extractItems($res->json());
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        foreach ($items as $fac) {
            $this->assertArrayHasKey('id', $fac);
            $this->assertArrayHasKey('codigo', $fac);
            $this->assertArrayHasKey('nombre', $fac);
            $this->assertArrayHasKey('universidad_id', $fac);

            // include opcional
            if (array_key_exists('escuelas_profesionales', $fac)) {
                $this->assertIsArray($fac['escuelas_profesionales']);
                foreach ($fac['escuelas_profesionales'] as $esc) {
                    $this->assertArrayHasKey('id', $esc);
                    $this->assertArrayHasKey('codigo', $esc);
                    $this->assertArrayHasKey('nombre', $esc);
                }
            }
        }
    }

    #[Test]
    public function index_admin_requiere_auth(): void
    {
        $this->getJson('/api/administrador/academico/facultades')
            ->assertStatus(401);
    }
}
