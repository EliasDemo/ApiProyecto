<?php
declare(strict_types=1);

namespace Tests\Feature\Academico;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\Universidad;
use PHPUnit\Framework\Attributes\Test;

final class SedesAdminTest extends ApiTestCase
{
    use AuthHelpers;

    private function extractItems(array $payload): array
    {
        return isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
    }

    #[Test]
    public function index_admin_lista_sedes_por_universidad(): void
    {
        $headers = $this->loginAs('upeu.admin');
        $uni = Universidad::where('codigo', 'UPeU')->firstOrFail();

        $res = $this->getJson("/api/administrador/academico/sedes?universidad_id={$uni->id}", $headers);
        $res->assertOk();

        $items = $this->extractItems($res->json());
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        foreach ($items as $s) {
            $this->assertArrayHasKey('id', $s);
            $this->assertArrayHasKey('nombre', $s);
            $this->assertArrayHasKey('es_principal', $s);
            $this->assertArrayHasKey('esta_suspendida', $s);
            $this->assertArrayHasKey('universidad_id', $s);
        }
    }

    #[Test]
    public function index_admin_requiere_auth(): void
    {
        $this->getJson('/api/administrador/academico/sedes')
            ->assertStatus(401);
    }
}
