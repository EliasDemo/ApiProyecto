<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\PeriodoAcademico;
use App\Models\EpSede;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class VMSesionEditarTest extends ApiTestCase
{
    use AuthHelpers;

    private function crearProyectoProcesoYSesionFutura(): array
    {
        $ep = EpSede::whereHas('escuelaProfesional', fn($q)=>$q->where('codigo','SIS'))
            ->whereHas('sede', fn($q)=>$q->where('nombre','Sede Lima'))
            ->firstOrFail();

        $periodo = \App\Models\PeriodoAcademico::where('es_actual', true)->firstOrFail();

        $proyectoId = (int) DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $ep->id,
            'periodo_id'   => $periodo->id,
            'codigo'       => 'PRJ-EDIT-'.uniqid(),
            'titulo'       => 'Proyecto editabilidad',
            'descripcion'  => 'Test edición de sesión',
            'tipo'         => 'VINCULADO',
            'modalidad'    => 'MIXTA',
            'estado'       => 'PLANIFICADO',
            'nivel'        => 5,
            'horas_planificadas'         => 30,
            'horas_minimas_participante' => 24,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $procesoId = (int) DB::table('vm_procesos')->insertGetId([
            'proyecto_id' => $proyectoId,
            'nombre' => 'Proc editar',
            'descripcion' => 'ed',
            'tipo_registro' => 'ASISTENCIA',
            'horas_asignadas' => null,
            'nota_minima' => null,
            'requiere_asistencia' => true,
            'orden' => 1,
            // sin 'estado' -> usa default de BD
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sesión futura dentro del período — sin 'estado' (usa default)
        $sesionId = (int) DB::table('vm_sesiones')->insertGetId([
            'sessionable_type' => \App\Models\VmProceso::class,
            'sessionable_id'   => $procesoId,
            'fecha'            => '2025-11-20',
            'hora_inicio'      => '10:00',
            'hora_fin'         => '12:00',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return [$proyectoId, $procesoId, $sesionId];
    }

    #[Test]
    public function get_edit_sesion_200_para_encargado_y_coordinador(): void
    {
        [$proyectoId, $procesoId, $sesionId] = $this->crearProyectoProcesoYSesionFutura();

        // ENCARGADO
        $headersEnc = $this->loginAs('upeu.luis');
        $this->getJson("/api/vm/sesiones/{$sesionId}/edit", $headersEnc)
            ->assertOk()
            ->assertJsonPath('ok', true);

        // COORDINADOR (lectura)
        $headersCoord = $this->loginAs('upeu.maria');
        $this->getJson("/api/vm/sesiones/{$sesionId}/edit", $headersCoord)
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function update_sesion_200_en_planificado_y_409_si_proyecto_no_planificado(): void
    {
        $headers = $this->loginAs('upeu.luis');
        [$proyectoId, $procesoId, $sesionId] = $this->crearProyectoProcesoYSesionFutura();

        // 200 en PLANIFICADO (cambiar hora dentro del mismo día)
        $this->putJson("/api/vm/sesiones/{$sesionId}", [
            'hora_inicio' => '11:00',
            'hora_fin'    => '13:00',
        ], $headers)->assertOk();

        // Poner el proyecto EN_CURSO
        $this->putJson("/api/vm/proyectos/{$proyectoId}/publicar", [], $headers)
            ->assertOk();

        // 409 al intentar editar sesión con proyecto no planificado
        $this->putJson("/api/vm/sesiones/{$sesionId}", [
            'hora_inicio' => '12:00',
            'hora_fin'    => '14:00',
        ], $headers)->assertStatus(409);
    }

    #[Test]
    public function delete_sesion_204_en_planificado_y_409_si_no_planificado(): void
    {
        $headers = $this->loginAs('upeu.luis');
        [$proyectoId, $procesoId, $sesionId] = $this->crearProyectoProcesoYSesionFutura();

        // 204 en PLANIFICADO
        $this->deleteJson("/api/vm/sesiones/{$sesionId}", [], $headers)
            ->assertNoContent();

        // Crear otra sesión y poner proyecto EN_CURSO para forzar 409 — sin 'estado'
        $sesion2 = (int) DB::table('vm_sesiones')->insertGetId([
            'sessionable_type' => \App\Models\VmProceso::class,
            'sessionable_id'   => $procesoId,
            'fecha'            => '2025-11-22',
            'hora_inicio'      => '08:00',
            'hora_fin'         => '10:00',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->putJson("/api/vm/proyectos/{$proyectoId}/publicar", [], $headers)
            ->assertOk();

        $this->deleteJson("/api/vm/sesiones/{$sesion2}", [], $headers)
            ->assertStatus(409);
    }
}
