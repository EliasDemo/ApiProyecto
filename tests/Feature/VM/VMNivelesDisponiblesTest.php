<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\EpSede;
use App\Models\PeriodoAcademico;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class VMNivelesDisponiblesTest extends ApiTestCase
{
    use AuthHelpers;

    #[Test]
    public function niveles_disponibles_excluye_niveles_ocupados(): void
    {
        $headers = $this->loginAs('upeu.luis'); // ENCARGADO SIS-Lima

        $ep = EpSede::whereHas('escuelaProfesional', fn($q)=>$q->where('codigo','SIS'))
            ->whereHas('sede', fn($q)=>$q->where('nombre','Sede Lima'))
            ->firstOrFail();

        $periodo = PeriodoAcademico::where('es_actual', true)->firstOrFail();

        // Ocupamos nivel 3 con un proyecto VINCULADO en este ep_sede + periodo
        DB::table('vm_proyectos')->insert([
            'ep_sede_id'   => $ep->id,
            'periodo_id'   => $periodo->id,
            'codigo'       => 'PRJ-NIV-3',
            'titulo'       => 'Proyecto Nivel 3',
            'descripcion'  => 'Para excluir nivel 3',
            'tipo'         => 'VINCULADO',
            'modalidad'    => 'PRESENCIAL',
            'estado'       => 'PLANIFICADO',
            'nivel'        => 3,
            'horas_planificadas'         => 32,
            'horas_minimas_participante' => 28,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $res = $this->getJson("/api/vm/proyectos/niveles-disponibles?ep_sede_id={$ep->id}&periodo_id={$periodo->id}", $headers);
        $res->assertOk();

        $arr = $res->json('data') ?? [];
        $this->assertIsArray($arr);
        $this->assertNotContains(3, $arr, 'El nivel 3 no debería estar disponible al estar ocupado.');
    }
}
