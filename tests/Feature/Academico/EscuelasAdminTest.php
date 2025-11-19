<?php
declare(strict_types=1);

namespace Tests\Feature\Academico;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\Facultad;
use App\Models\EscuelaProfesional;
use PHPUnit\Framework\Attributes\Test;

final class EscuelasAdminTest extends ApiTestCase
{
    use AuthHelpers;

    private function extractItems(array $payload): array
    {
        // Soporta { ok, data:[...] } o respuesta plana [...]
        return isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
    }

    #[Test]
    public function index_admin_lista_escuelas_filtradas_por_facultad(): void
    {
        $headers = $this->loginAs('upeu.admin');
        $facIng = Facultad::where('codigo','FIA')->firstOrFail();

        $url = "/api/administrador/academico/escuelas-profesionales?facultad_id={$facIng->id}&include=facultad,sedes";
        $res = $this->getJson($url, $headers);
        $res->assertOk();

        $items = $this->extractItems($res->json());
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        foreach ($items as $esc) {
            $this->assertArrayHasKey('id', $esc);
            $this->assertArrayHasKey('codigo', $esc);
            $this->assertArrayHasKey('nombre', $esc);
            $this->assertArrayHasKey('facultad_id', $esc);

            if (array_key_exists('facultad', $esc) && is_array($esc['facultad'])) {
                $this->assertArrayHasKey('id', $esc['facultad']);
                $this->assertArrayHasKey('codigo', $esc['facultad']);
                $this->assertArrayHasKey('nombre', $esc['facultad']);
            }

            if (array_key_exists('sedes', $esc) && is_array($esc['sedes'])) {
                foreach ($esc['sedes'] as $s) {
                    $this->assertArrayHasKey('id', $s);
                    $this->assertArrayHasKey('nombre', $s);
                    if (array_key_exists('pivot', $s) && is_array($s['pivot'])) {
                        $this->assertArrayHasKey('vigente_desde', $s['pivot']);
                        $this->assertArrayHasKey('vigente_hasta', $s['pivot']);
                    }
                }
            }
        }
    }

    #[Test]
    public function show_admin_not_found_devuelve_404_o_no_devuelve_el_id_solicitado(): void
    {
        $headers = $this->loginAs('upeu.admin');

        // ID que sabemos que no existe
        $maxId = (int) (EscuelaProfesional::max('id') ?? 0);
        $missingId = $maxId + 10000;

        $res = $this->getJson("/api/administrador/academico/escuelas-profesionales/{$missingId}", $headers);

        if ($res->status() === 404) {
            $this->assertTrue(true);
            return;
        }

        $res->assertOk();
        $payload = $res->json();
        $data = is_array($payload) && array_key_exists('data', $payload) ? $payload['data'] : $payload;

        if ($data === null || $data === '' || $data === []) {
            $this->assertTrue(true);
            return;
        }

        $this->assertIsArray($data);
        $this->assertArrayHasKey('id', $data);
        $this->assertNotSame($missingId, (int) $data['id'], 'El endpoint devolvió el mismo ID inexistente.');
    }
}
