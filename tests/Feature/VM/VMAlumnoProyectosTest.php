<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\PeriodoAcademico;
use App\Models\EpSede;
use App\Models\VmProyecto;
use App\Models\EscuelaProfesional;
use App\Models\Sede;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class VMAlumnoProyectosTest extends ApiTestCase
{
    use AuthHelpers;

    #[Test]
    public function index_alumno_devuelve_contexto_y_listas_aun_sin_proyectos(): void
    {
        $headers = $this->loginAs('upeu.jorge');

        $res = $this->getJson('/api/vm/proyectos/alumno', $headers);
        $res->assertOk();

        $json = $res->json();
        $this->assertTrue($json['ok'] ?? false);
        $this->assertIsArray($json['data'] ?? null);

        $ctx = $json['data']['contexto'] ?? [];
        $this->assertArrayHasKey('ep_sede_id', $ctx);
        $this->assertArrayHasKey('periodo_id', $ctx);
        $this->assertArrayHasKey('ciclo_actual', $ctx);
        $this->assertArrayHasKey('tiene_pendiente_vinculado', $ctx);
    }

    #[Test]
    public function show_alumno_de_proyecto_en_curso_es_visible(): void
    {
        $headers = $this->loginAs('upeu.jorge');

        // ep_sede = SIS — Sede Lima
        $ep = EpSede::whereHas('escuelaProfesional', fn($q)=>$q->where('codigo','SIS'))
            ->whereHas('sede', fn($q)=>$q->where('nombre','Sede Lima'))
            ->firstOrFail();

        $periodo = PeriodoAcademico::where('es_actual', true)->firstOrFail();

        // Creamos proyecto EN_CURSO en esa EP-SEDE y periodo (tipo LIBRE para no exigir nivel)
        $id = DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $ep->id,
            'periodo_id'   => $periodo->id,
            'codigo'       => 'PRJ-ALUM-EN-CURSO',
            'titulo'       => 'Proyecto Libre en Curso',
            'descripcion'  => 'Solo lectura para alumno',
            'tipo'         => 'LIBRE',
            'modalidad'    => 'PRESENCIAL',
            'estado'       => 'EN_CURSO',
            'nivel'        => null,
            'horas_planificadas'         => 20,
            'horas_minimas_participante' => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $res = $this->getJson("/api/vm/alumno/proyectos/{$id}", $headers);
        $res->assertOk();

        $data = $res->json('data') ?? [];
        $this->assertIsArray($data);
        $this->assertArrayHasKey('proyecto', $data);
        $this->assertArrayHasKey('procesos', $data);
        $this->assertSame('EN_CURSO', $data['proyecto']['estado'] ?? null);
    }
}
