<?php
declare(strict_types=1);

namespace Tests\Feature\Lookups;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Test;

final class LookupsPeriodosTest extends ApiTestCase
{
    use AuthHelpers;

    #[Test]
    public function requiere_auth(): void
    {
        $this->getJson('/api/lookups/periodos')->assertStatus(401);
    }

    #[Test]
    public function lista_periodos_con_shape_minimo(): void
    {
        $headers = $this->loginAs('upeu.admin');

        $res = $this->getJson('/api/lookups/periodos?limit=50', $headers);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)
                     ->has('items', fn ($arr) =>
                         $arr->each(fn ($it) =>
                             $it->hasAll([
                                    'id','label','anio','ciclo','estado',
                                    'es_actual','fecha_inicio','fecha_fin'
                                ])
                                ->where('id', fn ($v) => is_int($v) || is_numeric($v))
                                ->where('anio', fn ($v) => is_int($v) || is_numeric($v))
                                ->where('ciclo', fn ($v) => is_int($v) || is_numeric($v))
                                ->where('fecha_inicio', fn ($s) => is_string($s) && strlen($s) >= 8)
                                ->where('fecha_fin', fn ($s) => is_string($s) && strlen($s) >= 8)
                         )
                     )
                     ->etc()
            );
    }

    #[Test]
    public function solo_activos_devuelve_en_curso(): void
    {
        $headers = $this->loginAs('upeu.admin');

        $res = $this->getJson('/api/lookups/periodos?solo_activos=1&limit=50', $headers);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)->has('items')->etc()
            );

        $items = $res->json('items') ?? [];
        $this->assertIsArray($items);

        // Si hay períodos en curso (seed: 2025-2), todos deben tener estado EN_CURSO
        foreach ($items as $p) {
            $this->assertEquals('EN_CURSO', $p['estado'] ?? null);
        }
    }

    #[Test]
    public function filtro_q_por_codigo(): void
    {
        $headers = $this->loginAs('upeu.admin');

        $res = $this->getJson('/api/lookups/periodos?q=2025-2&limit=50', $headers);
        $res->assertOk();

        $items = $res->json('items') ?? [];
        $this->assertIsArray($items);
        // Si existe, alguno tendrá label con "2025 - 2" (según tu Resource/Controller)
        if (!empty($items)) {
            $this->assertTrue(
                collect($items)->contains(fn ($i) => str_contains((string)($i['label'] ?? ''), '2025')),
                'Se esperaba que al menos un label contenga "2025".'
            );
        }
    }
}
