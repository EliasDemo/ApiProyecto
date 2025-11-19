<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\PeriodoAcademico;
use App\Models\EpSede;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class VMSesionesBatchTest extends ApiTestCase
{
    use AuthHelpers;

    private function proyectoPlanificado(): array
    {
        $ep = EpSede::whereHas('escuelaProfesional', fn($q)=>$q->where('codigo','SIS'))
            ->whereHas('sede', fn($q)=>$q->where('nombre','Sede Lima'))
            ->firstOrFail();
        $periodo = PeriodoAcademico::where('es_actual', true)->firstOrFail();

        $proyectoId = (int) DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $ep->id,
            'periodo_id'   => $periodo->id,
            'codigo'       => 'PRJ-SESS-PLAN-'.uniqid(),
            'titulo'       => 'Proyecto sesiones (PLANIFICADO)',
            'descripcion'  => 'Batch OK',
            'tipo'         => 'VINCULADO',
            'modalidad'    => 'PRESENCIAL',
            'estado'       => 'PLANIFICADO',
            'nivel'        => 4,
            'horas_planificadas'         => 30,
            'horas_minimas_participante' => 24,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $proc = (int) DB::table('vm_procesos')->insertGetId([
            'proyecto_id' => $proyectoId,
            'nombre' => 'Proc sesiones',
            'descripcion' => 'proc',
            'tipo_registro' => 'ASISTENCIA',
            'horas_asignadas' => null,
            'nota_minima' => null,
            'requiere_asistencia' => true,
            'orden' => 1,
            // ❌ sin 'estado' (usa default)
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$proyectoId, $proc, $periodo];
    }

    #[Test]
    public function batch_mode_list_dentro_del_periodo_201(): void
    {
        $headers = $this->loginAs('upeu.luis');
        [$proyectoId, $procesoId, $periodo] = $this->proyectoPlanificado();

        $payload = [
            'mode' => 'list',
            'hora_inicio' => '09:00',
            'hora_fin' => '11:00',
            'fechas' => ['2025-11-01','2025-11-08'], // dentro del rango de 2025-2
        ];

        $res = $this->postJson("/api/vm/procesos/{$procesoId}/sesiones/batch", $payload, $headers);
        $res->assertCreated()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function batch_fuera_de_periodo_422(): void
    {
        $headers = $this->loginAs('upeu.luis');
        [$proyectoId, $procesoId, $periodo] = $this->proyectoPlanificado();

        $payload = [
            'mode' => 'list',
            'hora_inicio' => '09:00',
            'hora_fin' => '11:00',
            'fechas' => ['2026-03-01'], // fuera del rango del periodo actual (2025-2)
        ];

        $this->postJson("/api/vm/procesos/{$procesoId}/sesiones/batch", $payload, $headers)
            ->assertStatus(422);
    }

    #[Test]
    public function batch_en_proyecto_no_planificado_409(): void
    {
        $headers = $this->loginAs('upeu.luis');
        [$proyectoId, $procesoId, $periodo] = $this->proyectoPlanificado();

        $this->putJson("/api/vm/proyectos/{$proyectoId}/publicar", [], $headers)
            ->assertOk();

        $payload = [
            'mode' => 'list',
            'hora_inicio' => '09:00',
            'hora_fin' => '11:00',
            'fechas' => ['2025-11-01'],
        ];

        $this->postJson("/api/vm/procesos/{$procesoId}/sesiones/batch", $payload, $headers)
            ->assertStatus(409);
    }

    #[Test]
    public function coordinador_no_puede_crear_batch_403(): void
    {
        $headersEnc = $this->loginAs('upeu.luis');
        [$proyectoId, $procesoId, $periodo] = $this->proyectoPlanificado();

        $payload = [
            'mode' => 'list',
            'hora_inicio' => '18:00',
            'hora_fin' => '20:00',
            'fechas' => ['2025-11-15'],
        ];

        // Coordinador intenta → 403
        $headersCoord = $this->loginAs('upeu.maria');
        $this->postJson("/api/vm/procesos/{$procesoId}/sesiones/batch", $payload, $headersCoord)
            ->assertForbidden();
    }
}
