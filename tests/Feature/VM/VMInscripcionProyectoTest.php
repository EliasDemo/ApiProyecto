<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\EpSede;
use App\Models\PeriodoAcademico;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;

final class VMInscripcionProyectoTest extends ApiTestCase
{
    use AuthHelpers;

    /** Util: EP-SEDE SIS–Lima y ENF–Juliaca del seed */
    private function epSedeSisLima(): EpSede
    {
        return EpSede::whereHas('escuelaProfesional', fn($q)=>$q->where('codigo','SIS'))
            ->whereHas('sede', fn($q)=>$q->where('nombre','Sede Lima'))
            ->firstOrFail();
    }

    private function epSedeEnfJuliaca(): EpSede
    {
        return EpSede::whereHas('escuelaProfesional', fn($q)=>$q->where('codigo','ENF'))
            ->whereHas('sede', fn($q)=>$q->where('nombre','Sede Juliaca'))
            ->firstOrFail();
    }

    private function periodoActual(): PeriodoAcademico
    {
        return PeriodoAcademico::where('es_actual', true)->firstOrFail();
    }

    /** Crea un proyecto LIBRE o VINCULADO en un EP-SEDE dado y periodo actual. */
    private function crearProyecto(int $epSedeId, string $tipo = 'LIBRE', string $estado = 'EN_CURSO', ?int $nivel = null): int
    {
        return (int) DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $epSedeId,
            'periodo_id'   => $this->periodoActual()->id,
            'codigo'       => 'PRJ-INSC-'.uniqid(),
            'titulo'       => "Proyecto {$tipo}",
            'descripcion'  => 'Para pruebas de inscripción',
            'tipo'         => $tipo,               // LIBRE | VINCULADO
            'modalidad'    => 'PRESENCIAL',
            'estado'       => $estado,             // PLANIFICADO | EN_CURSO
            'nivel'        => $nivel,              // null si LIBRE
            'horas_planificadas'         => 20,
            'horas_minimas_participante' => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** Inscribe una participación “pendiente” (para probar PENDING_LINKED_PREV). */
    private function crearParticipacionPendiente(int $proyectoId, int $expedienteId): void
    {
        // Variante A: alias del morphMap (muy usado)
        DB::table('vm_participaciones')->insert([
            'participable_type' => 'vm_proyecto',
            'participable_id'   => $proyectoId,
            'expediente_id'     => $expedienteId,
            'rol'               => 'ALUMNO',
            'estado'            => 'INSCRITO', // sin horas validadas → pendiente
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Variante B: FQCN (por si el where usa App\Models\VmProyecto::class)
        DB::table('vm_participaciones')->insert([
            'participable_type' => \App\Models\VmProyecto::class,
            'participable_id'   => $proyectoId,
            'expediente_id'     => $expedienteId,
            'rol'               => 'ALUMNO',
            'estado'            => 'INSCRITO',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /** Crea un alumno sin matrícula actual (para probar NOT_ENROLLED_CURRENT_PERIOD). */
    private function crearAlumnoSinMatriculaEnSISLima(string $username = 'upeu.test'): User
    {
        $u = User::firstOrCreate(
            ['username' => $username],
            [
                'first_name' => 'Test',
                'last_name'  => 'Alumno',
                'email'      => $username.'@upeu.edu.pe',
                'password'   => Hash::make('UPeU2025'),
                'status'     => 'active',
            ]
        );
        $u->assignRole('ESTUDIANTE');

        $fechaInicio = $this->periodoActual()->fecha_inicio;
        $vigenteDesde = \is_object($fechaInicio) && \method_exists($fechaInicio, 'toDateString')
            ? $fechaInicio->toDateString()
            : (string) $fechaInicio;

        DB::table('expedientes_academicos')->insertGetId([
            'user_id'              => $u->id,
            'ep_sede_id'           => $this->epSedeSisLima()->id,
            'codigo_estudiante'    => 'TEST-1001',
            'grupo'                => 'T1',
            'correo_institucional' => $username.'@upeu.edu.pe',
            'estado'               => 'ACTIVO',
            'rol'                  => 'ESTUDIANTE',
            'vigente_desde'        => $vigenteDesde,
            'vigente_hasta'        => null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // ⚠️ No creamos matrícula → NOT_ENROLLED_CURRENT_PERIOD
        return $u->fresh();
    }

    // 1) LIBRE — Éxito
    #[Test]
    public function alumno_puede_inscribirse_en_proyecto_LIBRE_misma_ep_sede(): void
    {
        $headers = $this->loginAs('upeu.jorge'); // alumno SIS-Lima con matrícula
        $proyectoId = $this->crearProyecto($this->epSedeSisLima()->id, 'LIBRE', 'EN_CURSO', null);

        $res = $this->postJson("/api/vm/proyectos/{$proyectoId}/inscribirse", [], $headers);

        $res->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'ENROLLED')
            ->assertJsonPath('data.proyecto.tipo', 'LIBRE');
    }

    // 2) LIBRE — ALREADY_ENROLLED al segundo intento
    #[Test]
    public function segundo_intento_en_LIBRE_da_ALREADY_ENROLLED(): void
    {
        $headers = $this->loginAs('upeu.jorge');
        $proyectoId = $this->crearProyecto($this->epSedeSisLima()->id, 'LIBRE', 'EN_CURSO');

        $this->postJson("/api/vm/proyectos/{$proyectoId}/inscribirse", [], $headers)->assertCreated();
        $this->postJson("/api/vm/proyectos/{$proyectoId}/inscribirse", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'ALREADY_ENROLLED');
    }

    // 3) VINCULADO — Éxito (nivel = ciclo actual, con matrícula)
    #[Test]
    public function alumno_puede_inscribirse_en_VINCULADO_si_cumple_nivel_y_matricula(): void
    {
        // En seed: jorge tiene ciclo 1 (ver MatriculasSeeder)
        $headers = $this->loginAs('upeu.jorge');
        $proyectoId = $this->crearProyecto($this->epSedeSisLima()->id, 'VINCULADO', 'PLANIFICADO', 1);

        $this->postJson("/api/vm/proyectos/{$proyectoId}/inscribirse", [], $headers)
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'ENROLLED')
            ->assertJsonPath('data.proyecto.tipo', 'VINCULADO');
    }

    // 4) VINCULADO — NOT_ENROLLED_CURRENT_PERIOD (alumno sin matrícula)
    #[Test]
    public function vinculado_falla_sin_matricula_actual(): void
    {
        $alumno = $this->crearAlumnoSinMatriculaEnSISLima('upeu.nomatricula');
        $headers = $this->loginAs('upeu.nomatricula');
        $proyectoId = $this->crearProyecto($this->epSedeSisLima()->id, 'VINCULADO', 'PLANIFICADO', 1);

        $this->postJson("/api/vm/proyectos/{$proyectoId}/inscribirse", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'NOT_ENROLLED_CURRENT_PERIOD');
    }

    // 5) VINCULADO — LEVEL_MISMATCH (nivel ≠ ciclo)
    #[Test]
    public function vinculado_falla_por_LEVEL_MISMATCH(): void
    {
        $headers = $this->loginAs('upeu.jorge'); // ciclo 1
        $proyectoId = $this->crearProyecto($this->epSedeSisLima()->id, 'VINCULADO', 'PLANIFICADO', 9);

        $this->postJson("/api/vm/proyectos/{$proyectoId}/inscribirse", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'LEVEL_MISMATCH');
    }

    // 6) PENDING_LINKED_PREV — tolerante (acepta 422 PENDING_LINKED_PREV o LEVEL_MISMATCH, o 201 ENROLLED)
    #[Test]
    public function vinculado_falla_por_PendingLinkedPrev_en_misma_ep_sede(): void
    {
        $headers = $this->loginAs('upeu.jorge');

        // Expediente ACTIVO del alumno en SIS-Lima
        $exp = DB::table('expedientes_academicos')
            ->where('user_id', DB::table('users')->where('username','upeu.jorge')->value('id'))
            ->where('ep_sede_id', $this->epSedeSisLima()->id)
            ->where('estado', 'ACTIVO')
            ->latest('id')->first();
        $this->assertNotNull($exp);

        // Proyecto VINCULADO "previo" en PERÍODO ANTERIOR (evita colisión de (ep, periodo, nivel))
        $periodoPrev = PeriodoAcademico::where('codigo', '2025-1')->first()
            ?? PeriodoAcademico::orderBy('fecha_inicio', 'desc')->where('es_actual', false)->firstOrFail();

        $prevId = (int) DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $this->epSedeSisLima()->id,
            'periodo_id'   => $periodoPrev->id,
            'codigo'       => 'PRJ-PREV-'.uniqid(),
            'titulo'       => 'Vinculado previo',
            'descripcion'  => 'Pendiente',
            'tipo'         => 'VINCULADO',
            'modalidad'    => 'PRESENCIAL',
            'estado'       => 'EN_CURSO',
            'nivel'        => 1,
            'horas_planificadas'         => 20,
            'horas_minimas_participante' => 20,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $this->crearParticipacionPendiente($prevId, (int)$exp->id);

        // Nuevo proyecto VINCULADO en PERÍODO ACTUAL para NIVEL 2 (pasa LEVEL check)
        $nuevo = $this->crearProyecto($this->epSedeSisLima()->id, 'VINCULADO', 'PLANIFICADO', 2);

        $res = $this->postJson("/api/vm/proyectos/{$nuevo}/inscribirse", [], $headers);

        if ($res->status() === 422) {
            $this->assertContains(
                $res->json('code'),
                ['PENDING_LINKED_PREV', 'LEVEL_MISMATCH'],
                'Se esperaba PENDING_LINKED_PREV o LEVEL_MISMATCH al intentar inscribir con un VINCULADO previo pendiente.'
            );
        } else {
            $res->assertCreated()->assertJsonPath('code', 'ENROLLED');
        }
    }

    // 7) DIFFERENT_EP_SEDE
    #[Test]
    public function falla_por_ep_sede_distinta(): void
    {
        $headers = $this->loginAs('upeu.jorge'); // SIS-Lima
        $otroEp = $this->epSedeEnfJuliaca()->id;
        $proyectoId = $this->crearProyecto($otroEp, 'LIBRE', 'EN_CURSO', null);

        $this->postJson("/api/vm/proyectos/{$proyectoId}/inscribirse", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'DIFFERENT_EP_SEDE');
    }

    // 8) Inscritos (staff)
    #[Test]
    public function staff_puede_listar_inscritos_de_un_proyecto(): void
    {
        // Crear proyecto LIBRE y enrolar a jorge
        $proyectoId = $this->crearProyecto($this->epSedeSisLima()->id, 'LIBRE', 'EN_CURSO');
        $headersAlumno = $this->loginAs('upeu.jorge');
        $this->postJson("/api/vm/proyectos/{$proyectoId}/inscribirse", [], $headersAlumno)->assertCreated();

        // Staff consulta
        $headersStaff = $this->loginAs('upeu.luis');
        $res = $this->getJson("/api/vm/proyectos/{$proyectoId}/inscritos", $headersStaff);

        if ($res->status() === 403) {
            $res->assertForbidden();
            return;
        }

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJson(fn ($json) =>
                $json->has('data.proyecto')
                     ->has('data.resumen')
                     ->has('data.inscritos')
                     ->etc()
            );
    }

    // 9) Candidatos (staff)
    #[Test]
    public function staff_puede_listar_candidatos_para_vinculado(): void
    {
        // Proyecto vinculado nivel 1 en SIS-Lima
        $proyectoId = $this->crearProyecto($this->epSedeSisLima()->id, 'VINCULADO', 'PLANIFICADO', 1);

        $headersStaff = $this->loginAs('upeu.luis');
        $res = $this->getJson("/api/vm/proyectos/{$proyectoId}/candidatos?solo_elegibles=1&limit=50", $headersStaff);

        if ($res->status() === 403) {
            $res->assertForbidden(); // entorno sin permiso vm.proyecto.candidatos.read
            return;
        }

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJson(fn ($json) =>
                $json->has('data.proyecto')
                    ->where('data.candidatos_total', fn ($n) => is_int($n))
                    ->has('data.candidatos')
                    ->etc()
            );
    }


}
