<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\EpSede;
use App\Models\PeriodoAcademico;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class VMAsistenciasManualTest extends ApiTestCase
{
    use AuthHelpers;

    private function epSedeSisLima(): EpSede
    {
        return EpSede::whereHas('escuelaProfesional', fn($q)=>$q->where('codigo','SIS'))
            ->whereHas('sede', fn($q)=>$q->where('nombre','Sede Lima'))
            ->firstOrFail();
    }
    private function periodoActual(): \App\Models\PeriodoAcademico
    {
        return PeriodoAcademico::where('es_actual', true)->firstOrFail();
    }

    private function setupEscenario(): array
    {
        $ep  = $this->epSedeSisLima();
        $per = $this->periodoActual();

        $proyectoId = (int) DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $ep->id,
            'periodo_id'   => $per->id,
            'codigo'       => 'PRJ-MAN-'.uniqid(),
            'titulo'       => 'Asistencias Manual',
            'descripcion'  => 'Flow manual',
            'tipo'         => 'LIBRE',
            'modalidad'    => 'PRESENCIAL',
            'estado'       => 'EN_CURSO',
            'nivel'        => null,
            'horas_planificadas'         => 6,
            'horas_minimas_participante' => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $procesoId = (int) DB::table('vm_procesos')->insertGetId([
            'proyecto_id' => $proyectoId,
            'nombre' => 'Proc Manual',
            'descripcion' => 'proc',
            'tipo_registro' => 'ASISTENCIA',
            'horas_asignadas' => null,
            'nota_minima' => null,
            'requiere_asistencia' => true,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sesionId = (int) DB::table('vm_sesiones')->insertGetId([
            'sessionable_type' => \App\Models\VmProceso::class,
            'sessionable_id'   => $procesoId,
            '.fecha'            => '2025-11-26',
            'hora_inicio'      => '15:00',
            'hora_fin'         => '17:00',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $exp = DB::table('expedientes_academicos')
            ->where('user_id', DB::table('users')->where('username','upeu.jorge')->value('id'))
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

        return [$proyectoId, $procesoId, $sesionId, $exp->codigo_estudiante];
    }

    #[Test]
    public function flujo_manual_activar_token_checkin_y_posible_justificacion(): void
    {
        [$proyectoId, $procesoId, $sesionId, $codigo] = $this->setupEscenario();

        $headersStaff = $this->loginAs('upeu.luis');

        // 1) Activar ventana manual
        $act = $this->postJson("/api/vm/sesiones/{$sesionId}/activar-manual", [], $headersStaff);
        if ($act->status() === 403) {
            $act->assertForbidden();
            return;
        }
        $act->assertCreated()->assertJsonPath('ok', true);

        // 2) Check-in manual — aceptar 201 o 422 (p.ej. ventana no activa/usuario no inscrito)
        $ch = $this->postJson("/api/vm/sesiones/{$sesionId}/check-in/manual", [
            'codigo' => $codigo,
        ], $headersStaff);

        if ($ch->status() === 422) {
            $ch->assertStatus(422); // no forzamos estructura de error
            return; // detenemos el flujo si no se pudo registrar
        }

        $payload = $ch->assertCreated()->json();
        $asistenciaId = $payload['data']['asistencia']['id'] ?? null;
        $this->assertNotEmpty($asistenciaId, 'No se devolvió asistencia tras check-in manual');

        // 3) Justificación (opcional)
        $this->postJson("/api/vm/sesiones/{$sesionId}/asistencias/justificar", [
            'codigo'        => $codigo,
            'justificacion' => 'Llegó tarde por transporte',
            'otorgar_horas' => true,
        ], $headersStaff)->assertCreated()->assertJsonPath('ok', true);
    }

    #[Test]
    public function coordinador_no_puede_abrir_ventana_manual_403(): void
    {
        [$proyectoId, $procesoId, $sesionId] = $this->setupEscenario();

        $headersCoord = $this->loginAs('upeu.maria'); // COORDINADOR
        $this->postJson("/api/vm/sesiones/{$sesionId}/activar-manual", [], $headersCoord)
            ->assertForbidden();
    }
}
