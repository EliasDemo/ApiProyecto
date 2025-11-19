<?php
declare(strict_types=1);

namespace Tests\Feature\Academico;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\Sede;
use PHPUnit\Framework\Attributes\Test;

final class SedeEscuelasAdminTest extends ApiTestCase
{
    use AuthHelpers;

    private function extractItems(array $payload): array
    {
        // Soporta { ok, data:[...] } o respuesta plana [...]
        return isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
    }

    #[Test]
    public function lista_escuelas_de_una_sede_y_valida_pivot_si_existe(): void
    {
        $headers = $this->loginAs('upeu.admin');
        $sede = Sede::where('nombre', 'Sede Lima')->firstOrFail();

        // Si tu endpoint acepta include, puedes probar con: ?include=sedes
        $res = $this->getJson("/api/administrador/academico/sedes/{$sede->id}/escuelas", $headers);
        $res->assertOk();

        $items = $this->extractItems($res->json());
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        foreach ($items as $esc) {
            $this->assertArrayHasKey('id', $esc);
            $this->assertArrayHasKey('codigo', $esc);
            $this->assertArrayHasKey('nombre', $esc);

            // El pivot puede venir en la raíz (esc['pivot']) o anidado en sedes[].pivot (si se incluyó la relación)
            $topPivot = array_key_exists('pivot', $esc) && is_array($esc['pivot']);
            $nestedPivot = false;

            if (array_key_exists('sedes', $esc) && is_array($esc['sedes'])) {
                foreach ($esc['sedes'] as $s) {
                    if (array_key_exists('pivot', $s) && is_array($s['pivot'])) {
                        $nestedPivot = true;
                        $this->assertArrayHasKey('vigente_desde', $s['pivot']);
                        $this->assertArrayHasKey('vigente_hasta', $s['pivot']);
                    }
                }
            }

            // El pivot es OPCIONAL para no forzar cómo devuelve el controller.
            if ($topPivot) {
                $this->assertArrayHasKey('vigente_desde', $esc['pivot']);
                $this->assertArrayHasKey('vigente_hasta', $esc['pivot']);
            } else {
                // No exigimos pivot si la API no lo incluye; el test sigue pasando.
                $this->assertTrue(true);
            }

            // Si viene anidado, ya se validó arriba.
            if (!$topPivot && $nestedPivot) {
                $this->assertTrue(true);
            }
        }
    }
}
