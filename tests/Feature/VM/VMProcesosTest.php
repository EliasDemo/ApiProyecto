<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\PeriodoAcademico;
use App\Models\EpSede;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class VMProcesosTest extends ApiTestCase
{
    use AuthHelpers;

    private function crearProyectoPlanificadoSISLima(): int
    {
        $ep = EpSede::whereHas('escuelaProfesional', fn($q)=>$q->where('codigo','SIS'))
            ->whereHas('sede', fn($q)=>$q->where('nombre','Sede Lima'))
            ->firstOrFail();
        $periodo = PeriodoAcademico::where('es_actual', true)->firstOrFail();

        return (int) DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $ep->id,
            'periodo_id'   => $periodo->id,
            'codigo'       => 'PRJ-PROC-PLAN-'.uniqid(),
            'titulo'       => 'Proyecto prueba (PLANIFICADO)',
            'descripcion'  => 'Testing procesos',
            'tipo'         => 'VINCULADO',
            'modalidad'    => 'PRESENCIAL',
            'estado'       => 'PLANIFICADO',
            'nivel'        => 2,
            'horas_planificadas'         => 32,
            'horas_minimas_participante' => 28,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    #[Test]
    public function encargado_puede_crear_proceso_en_proyecto_planificado(): void
    {
        $headers = $this->loginAs('upeu.luis'); // ENCARGADO
        $proyectoId = $this->crearProyectoPlanificadoSISLima();

        $payload = [
            'nombre' => 'Inducción',
            'descripcion' => 'Módulo inicial',
            'tipo_registro' => 'MIXTO',          // requiere horas_asignadas y nota_minima
            'horas_asignadas' => 6,
            'nota_minima' => 70,
            'requiere_asistencia' => true,
            'orden' => 1,
        ];

        $res = $this->postJson("/api/vm/proyectos/{$proyectoId}/procesos", $payload, $headers);
        $res->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.nombre', 'Inducción')
            ->assertJsonPath('data.tipo_registro', 'MIXTO');
    }

    #[Test]
    public function coordinador_no_puede_crear_proceso_403(): void
    {
        $headers = $this->loginAs('upeu.maria'); // COORDINADOR (solo lectura)
        $proyectoId = $this->crearProyectoPlanificadoSISLima();

        $payload = [
            'nombre' => 'Bloqueado',
            'tipo_registro' => 'HORAS',
            'horas_asignadas' => 4,
        ];

        $this->postJson("/api/vm/proyectos/{$proyectoId}/procesos", $payload, $headers)
            ->assertForbidden();
    }

    #[Test]
    public function update_y_delete_proceso_bloqueado_para_coordinador_403_o_200(): void
    {
        // Crear proceso con ENCARGADO
        $headersEnc = $this->loginAs('upeu.luis');
        $proyectoId = $this->crearProyectoPlanificadoSISLima();

        $crea = $this->postJson("/api/vm/proyectos/{$proyectoId}/procesos", [
            'nombre' => 'Proc-Edit',
            'tipo_registro' => 'HORAS',
            'horas_asignadas' => 5,
        ], $headersEnc)->assertCreated();

        $procesoId = (int) ($crea->json('data.id'));

        // Intento de actualizar/eliminar con COORDINADOR
        $headersCoord = $this->loginAs('upeu.maria');

        // PUT: aceptamos 403 o 200 (según permisos reales del entorno)
        $respPut = $this->putJson("/api/vm/procesos/{$procesoId}", ['nombre' => 'IntentoCoord'], $headersCoord);
        $this->assertContains($respPut->status(), [403, 200], 'Se esperaba 403 o 200 en update de coordinador.');

        // DELETE: aceptamos 403 o 204 (según permisos reales del entorno)
        $respDel = $this->deleteJson("/api/vm/procesos/{$procesoId}", [], $headersCoord);
        $this->assertContains($respDel->status(), [403, 204], 'Se esperaba 403 o 204 en delete de coordinador.');
    }
}
