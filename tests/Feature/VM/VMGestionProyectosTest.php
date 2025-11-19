<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\PeriodoAcademico;
use App\Models\EpSede;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class VMGestionProyectosTest extends ApiTestCase
{
    use AuthHelpers;

    #[Test]
    public function index_gestion_paginado_funciona_aun_sin_datos(): void
    {
        // ENCARGADO con permisos vm.proyecto.read
        $headers = $this->loginAs('upeu.luis');

        $res = $this->getJson('/api/vm/proyectos?expand=procesos,sesiones', $headers);
        $res->assertOk();

        // Paginación de Laravel: data.current_page, data.data, etc.
        $this->assertTrue($res->json('ok') ?? false);
        $this->assertIsArray($res->json('data'));
        $this->assertArrayHasKey('data', $res->json('data')); // items
    }

    #[Test]
    public function show_gestion_de_proyecto_creado_en_ep_sede_del_staff(): void
    {
        $headers = $this->loginAs('upeu.luis'); // ENCARGADO SIS-Lima

        $ep = EpSede::whereHas('escuelaProfesional', fn($q)=>$q->where('codigo','SIS'))
            ->whereHas('sede', fn($q)=>$q->where('nombre','Sede Lima'))
            ->firstOrFail();

        $periodo = \App\Models\PeriodoAcademico::where('es_actual', true)->firstOrFail();

        // Proyecto PLANIFICADO (staff puede verlo y editarlo en estado planificado)
        $id = DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $ep->id,
            'periodo_id'   => $periodo->id,
            'codigo'       => 'PRJ-GEST-PLAN',
            'titulo'       => 'Proyecto Gestión Planificado',
            'descripcion'  => 'Proyecto de prueba staff',
            'tipo'         => 'VINCULADO',
            'modalidad'    => 'MIXTA',
            'estado'       => 'PLANIFICADO',
            'nivel'        => 3,
            'horas_planificadas'         => 30,
            'horas_minimas_participante' => 24,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $res = $this->getJson("/api/vm/proyectos/{$id}?expand=procesos,sesiones", $headers);
        $res->assertOk();

        $data = $res->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('proyecto', $data);
        $this->assertArrayHasKey('procesos', $data);
        $this->assertSame('PLANIFICADO', $data['proyecto']['estado'] ?? null);
        $this->assertSame('VINCULADO', $data['proyecto']['tipo'] ?? null);
    }
}
