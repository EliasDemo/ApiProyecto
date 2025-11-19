<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\EpSede;
use App\Models\PeriodoAcademico;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class VMAsistenciasReportesTest extends ApiTestCase
{
    use AuthHelpers;

    private function epSedeSisLima(): EpSede
    {
        return EpSede::whereHas('escuelaProfesional', fn($q) => $q->where('codigo','SIS'))
            ->whereHas('sede', fn($q) => $q->where('nombre','Sede Lima'))
            ->firstOrFail();
    }

    private function periodoActual(): PeriodoAcademico
    {
        return PeriodoAcademico::where('es_actual', true)->firstOrFail();
    }

    /**
     * Crea proyecto EN_CURSO + proceso ASISTENCIA + sesión futura dentro del período actual
     * e inscribe a 'upeu.jorge'. Intenta abrir QR y hacer un check-in (tolerante a 201/422).
     * @return array [proyectoId, procesoId, sesionId, headersStaff]
     */
    private function setupEscenario(): array
    {
        $ep  = $this->epSedeSisLima();
        $per = $this->periodoActual();

        $proyectoId = (int) DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $ep->id,
            'periodo_id'   => $per->id,
            'codigo'       => 'PRJ-REP-'.uniqid(),
            'titulo'       => 'Asistencias Reportes',
            'descripcion'  => 'Flow Reportes',
            'tipo'         => 'LIBRE',
            'modalidad'    => 'PRESENCIAL',
            'estado'       => 'EN_CURSO',
            'nivel'        => null,
            'horas_planificadas'         => 8,
            'horas_minimas_participante' => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $procesoId = (int) DB::table('vm_procesos')->insertGetId([
            'proyecto_id'         => $proyectoId,
            'nombre'              => 'Proc Reporte',
            'descripcion'         => 'Prueba de reportes',
            'tipo_registro'       => 'ASISTENCIA',
            'horas_asignadas'     => null,
            'nota_minima'         => null,
            'requiere_asistencia' => true,
            'orden'               => 1,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $sesionId = (int) DB::table('vm_sesiones')->insertGetId([
            'sessionable_type' => \App\Models\VmProceso::class,
            'sessionable_id'   => $procesoId,
            'fecha'            => '2025-11-27',
            'hora_inicio'      => '09:00',
            'hora_fin'         => '11:00',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Inscribir a jorge en el proyecto
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

        // Abrir QR e intentar un check-in (tolerante a 201/422)
        $headersStaff = $this->loginAs('upeu.luis');
        $open = $this->postJson("/api/vm/sesiones/{$sesionId}/qr", ['max_usos' => 5], $headersStaff);

        if ($open->status() === 201) {
            $token = (string) $open->json('data.token');
            $headersAlumno = $this->loginAs('upeu.jorge');
            $check = $this->postJson("/api/vm/sesiones/{$sesionId}/check-in/qr", [
                'token' => $token,
            ], $headersAlumno);

            // Puede ser 201 (check-in creado) o 422 (ventana/geo/etc.)
            $this->assertTrue(in_array($check->status(), [201, 422], true));
        } else {
            $open->assertForbidden(); // si el staff no puede abrir QR en este entorno
        }

        return [$proyectoId, $procesoId, $sesionId, $headersStaff];
    }

    #[Test]
    public function participantes_y_asistencias_json_y_csv(): void
    {
        [$proyectoId, $procesoId, $sesionId, $headersStaff] = $this->setupEscenario();

        // Participantes (STAFF)
        $this->getJson("/api/vm/sesiones/{$sesionId}/participantes", $headersStaff)
            ->assertOk()
            ->assertJson(fn($json) => $json->where('ok', true)->has('data')->etc());

        // Asistencias JSON (STAFF)
        $this->getJson("/api/vm/sesiones/{$sesionId}/asistencias", $headersStaff)
            ->assertOk()
            ->assertJson(fn($json) => $json->where('ok', true)->has('data')->etc());

        // Reporte JSON
        $this->getJson("/api/vm/sesiones/{$sesionId}/asistencias/reporte?format=json", $headersStaff)
            ->assertOk();

        // Reporte CSV (aceptar 200 o 204 si no hay filas)
        $csv = $this->get("/api/vm/sesiones/{$sesionId}/asistencias/reporte?format=csv", $headersStaff);
        $this->assertTrue(
            in_array($csv->getStatusCode(), [200, 204], true),
            'La exportación de reporte debería responder 200 (o 204 si no hay filas).'
        );
    }
}
