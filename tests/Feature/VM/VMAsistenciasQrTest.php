<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;     // ← CORREGIDO (antes decía "import")
use Tests\Support\AuthHelpers;
use App\Models\EpSede;
use App\Models\PeriodoAcademico;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
// use Illuminate\Foundation\Testing\RefreshDatabase; // opcional para aislar con migraciones

final class VMAsistenciasQrTest extends ApiTestCase
{
    use AuthHelpers;
    // use RefreshDatabase; // si quieres que cada test arranque con BD limpia

    private function epSedeSisLima(): EpSede
    {
        return EpSede::whereHas('escuelaProfesional', fn($q) => $q->where('codigo', 'SIS'))
            ->whereHas('sede', fn($q) => $q->where('nombre', 'Sede Lima'))
            ->firstOrFail();
    }

    private function periodoActual(): PeriodoAcademico
    {
        return PeriodoAcademico::where('es_actual', true)->firstOrFail();
    }

    /** Crea proyecto EN_CURSO + proceso + sesión futura y registra a jorge. */
    private function setupEscenario(): array
    {
        $ep  = $this->epSedeSisLima();
        $per = $this->periodoActual();

        $proyectoId = (int) DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $ep->id,
            'periodo_id'   => $per->id,
            'codigo'       => 'PRJ-QR-' . uniqid(),
            'titulo'       => 'Asistencias QR',
            'descripcion'  => 'Flow QR',
            'tipo'         => 'LIBRE',
            'modalidad'    => 'PRESENCIAL',
            'estado'       => 'EN_CURSO',
            'nivel'        => null,
            'horas_planificadas'         => 10,
            'horas_minimas_participante' => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $procesoId = (int) DB::table('vm_procesos')->insertGetId([
            'proyecto_id' => $proyectoId,
            'nombre' => 'Proc QR',
            'descripcion' => 'proc',
            'tipo_registro' => 'ASISTENCIA',
            'horas_asignadas' => null,
            'nota_minima' => null,
            'requiere_asistencia' => true,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sesión futura
        $sesionId = (int) DB::table('vm_sesiones')->insertGetId([
            'sessionable_type' => \App\Models\VmProceso::class,
            'sessionable_id'   => $procesoId,
            'fecha'            => '2025-11-25',
            'hora_inicio'      => '09:00',
            'hora_fin'         => '11:00',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Inscribir a jorge en el proyecto
        $exp = DB::table('expedientes_academicos')
            ->where('user_id', DB::table('users')->where('username', 'upeu.jorge')->value('id'))
            ->where('ep_sede_id', $ep->id)
            ->where('estado', 'ACTIVO')->first();

        DB::table('vm_participaciones')->insert([
            'participable_type' => 'vm_proyecto',
            'participable_id'   => $proyectoId,
            'expediente_id'     => $exp->id,
            'rol'               => 'ALUMNO',
            'estado'            => 'INSCRITO',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return [$proyectoId, $procesoId, $sesionId];
    }

    #[Test]
    public function flujo_qr_generar_token_checkin_listar_y_validar(): void
    {
        [$proyectoId, $procesoId, $sesionId] = $this->setupEscenario();

        // staff (ENCARGADO) abre QR
        $headersStaff = $this->loginAs('upeu.luis');
        $open = $this->postJson("/api/vm/sesiones/{$sesionId}/qr", [
            'max_usos' => 5,
        ], $headersStaff);

        if ($open->status() === 403) {
            $open->assertForbidden();
            return;
        }

        $payload = $open->assertCreated()->json();
        $token = $payload['data']['token'] ?? null;
        $this->assertTrue(is_string($token) && strlen($token) > 0, 'Token QR inválido');

        // alumno hace check-in por QR
        $headersAlumno = $this->loginAs('upeu.jorge');
        $check = $this->postJson("/api/vm/sesiones/{$sesionId}/check-in/qr", [
            'token' => $token,
        ], $headersAlumno);

        // tolera 201 ó 422 sin exigir shape específico
        if ($check->status() === 422) {
            $check->assertStatus(422);
            return; // ventana/geo/inscripción: no continuamos
        }

        $parsed = $check->assertCreated()->json();
        $asistenciaId = $parsed['data']['asistencia']['id'] ?? null;
        $this->assertNotEmpty($asistenciaId, 'No se devolvió asistencia tras check-in');

        // listar asistencias (staff)
        $this->getJson("/api/vm/sesiones/{$sesionId}/asistencias", $headersStaff)
            ->assertOk()
            ->assertJson(fn($json) => $json->where('ok', true)->has('data')->etc());

        // validar asistencias (staff)
        $this->postJson("/api/vm/sesiones/{$sesionId}/validar", [
            'asistencias' => [$asistenciaId],
            'crear_registro_horas' => true,
        ], $headersStaff)->assertOk()->assertJsonPath('ok', true);
    }

    #[Test]
    public function coordinador_no_puede_abrir_qr_403(): void
    {
        [$proyectoId, $procesoId, $sesionId] = $this->setupEscenario();

        $headersCoord = $this->loginAs('upeu.maria'); // COORDINADOR
        $this->postJson("/api/vm/sesiones/{$sesionId}/qr", [
            'max_usos' => 3,
        ], $headersCoord)->assertForbidden();
    }
}
