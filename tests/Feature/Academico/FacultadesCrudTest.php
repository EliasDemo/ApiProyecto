<?php
declare(strict_types=1);

namespace Tests\Feature\Academico;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\Universidad;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Test;

final class FacultadesCrudTest extends ApiTestCase
{
    use AuthHelpers;

    #[Test]
    public function admin_puede_listar_crear_actualizar_eliminar_facultad(): void
    {
        $headers = $this->loginAs('upeu.admin'); // ADMIN ya existe por seed
        $uni = Universidad::where('codigo','UPeU')->firstOrFail();

        // CREATE
        $created = $this->postJson('/api/administrador/academico/facultades', [
            'universidad_id' => $uni->id,
            'codigo'         => 'FTEST',
            'nombre'         => 'Fac. Test',
        ], $headers)->assertCreated()->json('data');

        $this->assertIsArray($created);
        $id = (int)($created['id'] ?? 0);
        $this->assertGreaterThan(0, $id);

        // INDEX — tolerante: con/sin 'ok'
        $idx = $this->getJson("/api/administrador/academico/facultades?universidad_id={$uni->id}", $headers);
        $idx->assertOk();
        $payload = $idx->json();

        if (\is_array($payload) && \array_key_exists('ok', $payload)) {
            $idx->assertJson(fn(AssertableJson $j) =>
                $j->where('ok', true)->has('data')->etc()
            );
        } else {
            // Respuesta tipo ResourceCollection { data:[...], links:{}, meta:{} }
            $this->assertArrayHasKey('data', $payload, 'Se esperaba clave data en la colección');
        }

        // UPDATE
        $upd = $this->putJson("/api/administrador/academico/facultades/{$id}", [
            'nombre' => 'Fac. Test Editada',
        ], $headers);
        $upd->assertStatus(200);
        $updPayload = $upd->json();
        if (\is_array($updPayload) && \array_key_exists('data', $updPayload)) {
            $this->assertSame('Fac. Test Editada', $updPayload['data']['nombre'] ?? null);
        } else {
            $this->assertSame('Fac. Test Editada', $updPayload['nombre'] ?? null);
        }

        // DELETE — aceptar 204 o 200 con ok=true (según tu implementación)
        $del = $this->deleteJson("/api/administrador/academico/facultades/{$id}", [], $headers);
        $this->assertTrue(in_array($del->status(), [204, 200], true), 'Se esperaba 204 o 200');
        if ($del->status() === 200) {
            $del->assertJson(fn(AssertableJson $j) => $j->where('ok', true)->etc());
        }
    }
}
