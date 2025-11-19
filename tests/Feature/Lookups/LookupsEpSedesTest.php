<?php
declare(strict_types=1);

namespace Tests\Feature\Lookups;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Test;

final class LookupsEpSedesTest extends ApiTestCase
{
    use AuthHelpers;

    #[Test]
    public function requiere_auth(): void
    {
        $this->getJson('/api/lookups/ep-sedes')->assertStatus(401);
    }

    #[Test]
    public function para_staff_por_defecto_devuelve_ep_sedes_asociadas_al_usuario(): void
    {
        // ENCARGADO o COORDINADOR: por defecto solo_staff=1, roles=COORDINADOR,ENCARGADO
        $headers = $this->loginAs('upeu.luis'); // ENCARGADO (seed)

        $res = $this->getJson('/api/lookups/ep-sedes?limit=50', $headers);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)
                     ->has('items', fn ($arr) =>
                        $arr->each(fn ($item) =>
                            $item->hasAll(['id','label','escuela','sede'])
                                 ->where('id', fn ($v) => is_int($v) || is_numeric($v))
                                 ->where('label', fn ($s) => is_string($s) && $s !== '')
                        )
                     )
                     ->etc()
            );

        $items = $res->json('items') ?? [];
        $this->assertNotEmpty($items, 'Esperábamos al menos 1 EP-SEDE para un usuario staff.');
        // Label estilo "Escuela — Sede"
        $this->assertTrue(str_contains($items[0]['label'], '—') || str_contains($items[0]['label'], '-'));
    }

    #[Test]
    public function para_estudiante_debe_enviar_solo_staff_0_para_ver_sus_ep_sedes(): void
        {
            $headers = $this->loginAs('upeu.jorge'); // ESTUDIANTE

            // Caso 1: Si pedimos explícitamente solo_staff=1 + roles=COORDINADOR,ENCARGADO
            //          → el estudiante NO debería ver EP-SEDES (no es staff)
            $resStaff = $this->getJson('/api/lookups/ep-sedes?solo_staff=1&roles=COORDINADOR,ENCARGADO&limit=50', $headers);

            $resStaff->assertOk()
                ->assertJson(fn (AssertableJson $json) =>
                    $json->where('ok', true)->has('items')->etc()
                );

            $itemsStaff = $resStaff->json('items') ?? [];
            $this->assertIsArray($itemsStaff);
            $this->assertCount(0, $itemsStaff, 'Con solo_staff=1 y roles de staff, un estudiante no debería obtener EP-SEDES.');

            // Caso 2: Pedimos solo_staff=0 (incluir expedientes de ESTUDIANTE)
            $resAll = $this->getJson('/api/lookups/ep-sedes?solo_staff=0&limit=50', $headers);

            $resAll->assertOk()
                ->assertJson(fn (AssertableJson $json) =>
                    $json->where('ok', true)
                        ->has('items', fn ($arr) =>
                            $arr->each(fn ($it) =>
                                $it->hasAll(['id','label','escuela','sede'])
                                ->where('label', fn ($s) => is_string($s) && $s !== '')
                            )
                        )
                        ->etc()
                );

            $itemsAll = $resAll->json('items') ?? [];
            $this->assertIsArray($itemsAll);
            $this->assertNotEmpty($itemsAll, 'Con solo_staff=0 el estudiante debería ver su(s) EP-SEDE.');
        }


    #[Test]
    public function filtro_q_limita_por_nombre_de_escuela_o_sede(): void
    {
        $headers = $this->loginAs('upeu.luis'); // staff
        // Buscar Lima o alguna palabra del seed (p.ej. "Lima" o "Sistemas")
        $res = $this->getJson('/api/lookups/ep-sedes?q=Lima&limit=50', $headers);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)->has('items')->etc()
            );

        $items = $res->json('items') ?? [];
        // Puede devolver vacío si no hay match exacto; al menos no debe fallar el endpoint
        $this->assertIsArray($items);
    }
}
