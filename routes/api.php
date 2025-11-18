<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Api\EpSede\EpSedeStaffController;
use App\Http\Controllers\Api\Matricula\MatriculaManualController;
use App\Http\Controllers\Api\Matricula\MatriculaRegistroController;
use App\Http\Controllers\Api\Reportes\HorasPorPeriodoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────────────────────────────────────
// CONTROLADORES
// ─────────────────────────────────────────────────────────────────────────────

// Auth & Users
use App\Http\Controllers\Api\Login\AuthController;
use App\Http\Controllers\Api\User\UserController;

// Lookups & Universidad
use App\Http\Controllers\Api\Lookup\LookupController;
use App\Http\Controllers\Api\Universidad\UniversidadController;

// Académico (API)
use App\Http\Controllers\Api\Academico\EscuelaProfesionalApiController;
use App\Http\Controllers\Api\Academico\FacultadApiController;
use App\Http\Controllers\Api\Academico\SedeApiController;
use App\Http\Controllers\Api\Reportes\ReporteHorasController;

// VM (Virtual Manager)
use App\Http\Controllers\Api\Vm\ProyectoController;
use App\Http\Controllers\Api\Vm\ProyectoProcesoController;
use App\Http\Controllers\Api\Vm\ProcesoSesionController;
use App\Http\Controllers\Api\Vm\InscripcionProyectoController;
use App\Http\Controllers\Api\Vm\ProyectoImagenController;
use App\Http\Controllers\Api\Vm\EventoController;
use App\Http\Controllers\Api\Vm\AgendaController;
use App\Http\Controllers\Api\Vm\AsistenciasController;
use App\Http\Controllers\Api\Vm\EventoImagenController;

// ─────────────────────────────────────────────────────────────────────────────
// AUTENTICACIÓN Y USUARIOS
// ─────────────────────────────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->get('/user', fn (Request $r) => $r->user());

Route::prefix('auth')->group(function () {
    Route::post('/lookup', [AuthController::class, 'lookup']);
    Route::post('/login',  [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum'])->prefix('users')->group(function () {
    Route::get('/me', [UserController::class, 'me']);
    Route::get('/by-username/{username}', [UserController::class, 'showByUsername']);
});

// ─────────────────────────────────────────────────────────────────────────────
// LOOKUPS
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum'])->prefix('lookups')->group(function () {
    Route::get('/ep-sedes',  [LookupController::class, 'epSedes']);   // ?q=...&limit=...
    Route::get('/periodos',  [LookupController::class, 'periodos']);  // ?q=...&solo_activos=1
});

// ─────────────────────────────────────────────────────────────────────────────
// VM (Virtual Manager)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * 1️⃣ RUTAS PARA ALUMNO (autenticado; sin permisos adicionales)
 */
Route::middleware(['auth:sanctum'])->prefix('vm')->group(function () {
    Route::get('/proyectos/alumno', [ProyectoController::class, 'indexAlumno'])
        ->name('vm.proyectos.index-alumno');

    Route::post('/proyectos/{proyecto}/inscribirse', [InscripcionProyectoController::class, 'inscribirProyecto'])
        ->whereNumber('proyecto')
        ->name('vm.proyectos.inscribirse');

    Route::get('/alumno/agenda', [AgendaController::class, 'agendaAlumno'])
        ->name('vm.alumno.agenda');

    Route::post('/sesiones/{sesion}/check-in/qr', [AsistenciasController::class, 'checkInPorQr'])
        ->whereNumber('sesion')
        ->name('vm.sesiones.checkin-qr');

    Route::get('/alumno/proyectos/{proyecto}', [ProyectoController::class, 'show'])
        ->whereNumber('proyecto')
        ->name('vm.alumno.proyectos.show');
});

Route::any('/debug-method', function (Request $request) {
    return response()->json([
        'method'  => $request->method(),
        'headers' => $request->headers->all(),
        'body'    => $request->all(),
    ]);
});

/**
 * 2️⃣ RUTAS DE GESTIÓN (con permisos por endpoint)
 */
Route::middleware(['auth:sanctum'])->prefix('vm')->group(function () {
    Route::get('/proyectos/niveles-disponibles', [ProyectoController::class, 'nivelesDisponibles'])
        ->middleware('permission:vm.proyecto.niveles.read')
        ->name('vm.proyectos.niveles-disponibles');

    Route::get('/proyectos', [ProyectoController::class, 'index'])
        ->middleware('permission:vm.proyecto.read');

    Route::post('/proyectos', [ProyectoController::class, 'store'])
        ->middleware('permission:vm.proyecto.create');

    Route::get('/proyectos/{proyecto}', [ProyectoController::class, 'show'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.read');

    Route::get('/proyectos/{proyecto}/edit', [ProyectoController::class, 'edit'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.update');

    Route::put('/proyectos/{proyecto}', [ProyectoController::class, 'update'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.update');

    Route::delete('/proyectos/{proyecto}', [ProyectoController::class, 'destroy'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.delete');

    Route::put('/proyectos/{proyecto}/publicar', [ProyectoController::class, 'publicar'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.publish');

    Route::get('/proyectos/{proyecto}/inscritos', [InscripcionProyectoController::class, 'listarInscritos'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.inscripciones.read');

    Route::get('/proyectos/{proyecto}/candidatos', [InscripcionProyectoController::class, 'listarCandidatos'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.candidatos.read');

    Route::get('/proyectos/{proyecto}/imagenes', [ProyectoImagenController::class, 'index'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.imagen.read');

    Route::post('/proyectos/{proyecto}/imagenes', [ProyectoImagenController::class, 'store'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.imagen.create');

    Route::delete('/proyectos/{proyecto}/imagenes/{imagen}', [ProyectoImagenController::class, 'destroy'])
        ->whereNumber('proyecto')->whereNumber('imagen')
        ->middleware('permission:vm.proyecto.imagen.delete');

    // Procesos y sesiones
    Route::post('/proyectos/{proyecto}/procesos', [ProyectoProcesoController::class, 'store'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proceso.create');

    Route::put('/procesos/{proceso}', [ProyectoProcesoController::class, 'update'])
        ->whereNumber('proceso')
        ->middleware('permission:vm.proceso.update');

    Route::delete('/procesos/{proceso}', [ProyectoProcesoController::class, 'destroy'])
        ->whereNumber('proceso')
        ->middleware('permission:vm.proceso.delete');

    Route::post('/procesos/{proceso}/sesiones/batch', [ProcesoSesionController::class, 'storeBatch'])
        ->whereNumber('proceso')
        ->middleware('permission:vm.sesion.batch.create');

    Route::get('/procesos/{proceso}/edit', [ProyectoProcesoController::class, 'edit'])
        ->whereNumber('proceso')
        ->middleware('permission:vm.proceso.read');

    Route::get('/sesiones/{sesion}/edit', [ProcesoSesionController::class, 'editSesion'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.sesion.read');

    Route::put('/sesiones/{sesion}', [ProcesoSesionController::class, 'updateSesion'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.sesion.update');

    Route::delete('/sesiones/{sesion}', [ProcesoSesionController::class, 'destroySesion'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.sesion.delete');

    // Eventos
    Route::get('/eventos', [EventoController::class, 'index'])
        ->middleware('permission:vm.evento.read')
        ->name('vm.eventos.index');

    Route::get('/eventos/{evento}', [EventoController::class, 'show'])
        ->whereNumber('evento')
        ->middleware('permission:vm.evento.read')
        ->name('vm.eventos.show');

    Route::post('/eventos', [EventoController::class, 'store'])
        ->middleware('permission:vm.evento.create')
        ->name('vm.eventos.store');

    Route::put('/eventos/{evento}', [EventoController::class, 'update'])
        ->whereNumber('evento')
        ->middleware('permission:vm.evento.update')
        ->name('vm.eventos.update');

    Route::get('/eventos/{evento}/imagenes', [EventoImagenController::class, 'index'])
        ->whereNumber('evento')
        ->middleware('permission:vm.evento.imagen.read');

    Route::post('/eventos/{evento}/imagenes', [EventoImagenController::class, 'store'])
        ->whereNumber('evento')
        ->middleware('permission:vm.evento.imagen.create');

    Route::delete('/eventos/{evento}/imagenes/{imagen}', [EventoImagenController::class, 'destroy'])
        ->whereNumber('evento')->whereNumber('imagen')
        ->middleware('permission:vm.evento.imagen.delete');

    // Agenda staff
    Route::get('/staff/agenda', [AgendaController::class, 'agendaStaff'])
        ->middleware('permission:vm.agenda.staff.read')
        ->name('vm.staff.agenda');

    // Asistencias / QR
    Route::post('/sesiones/{sesion}/qr', [AsistenciasController::class, 'generarQr'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.asistencia.abrir_qr')
        ->name('vm.sesiones.abrir-qr');

    Route::post('/sesiones/{sesion}/activar-manual', [AsistenciasController::class, 'activarManual'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.asistencia.activar_manual')
        ->name('vm.sesiones.activar-manual');

    Route::post('/sesiones/{sesion}/check-in/manual', [AsistenciasController::class, 'checkInManual'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.asistencia.checkin.manual')
        ->name('vm.sesiones.checkin-manual');

    Route::get('/sesiones/{sesion}/participantes', [AsistenciasController::class, 'participantes'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.asistencia.participantes.read')
        ->name('vm.sesiones.participantes');

    Route::post('/sesiones/{sesion}/asistencias/justificar', [AsistenciasController::class, 'checkInFueraDeHora'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.asistencia.justificar.create')
        ->name('vm.sesiones.asistencias.justificar');

    Route::get('/sesiones/{sesion}/asistencias', [AsistenciasController::class, 'listarAsistencias'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.asistencia.read')
        ->name('vm.sesiones.asistencias');

    Route::get('/sesiones/{sesion}/asistencias/reporte', [AsistenciasController::class, 'reporte'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.asistencia.reporte.read')
        ->name('vm.sesiones.asistencias.reporte');

    Route::post('/sesiones/{sesion}/validar', [AsistenciasController::class, 'validarAsistencias'])
        ->whereNumber('sesion')
        ->middleware('permission:vm.asistencia.validar')
        ->name('vm.sesiones.validar');
});

Route::middleware('auth:sanctum')
    ->prefix('matriculas')
    ->name('matriculas.')
    ->group(function () {
        // Importación del Excel
        Route::post('import', [MatriculaRegistroController::class, 'import'])
            ->name('import');

        // Descarga de la plantilla Excel
        Route::get('plantilla', [MatriculaRegistroController::class, 'plantilla'])
            ->name('plantilla');

        // ── Flujo manual (opcional si lo pones en /matriculas/manual como te propuse)
        Route::prefix('manual')->name('manual.')->group(function () {
            Route::get('alumnos/buscar', [MatriculaManualController::class, 'buscar'])->name('buscar');
            Route::post('registrar',      [MatriculaManualController::class, 'registrarOActualizar'])->name('registrar');
            Route::post('matricular',     [MatriculaManualController::class, 'matricular'])->name('matricular');
            Route::patch('expedientes/{expediente}/estado', [MatriculaManualController::class, 'cambiarEstadoExpediente'])->name('expediente.estado');
        });
    });

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN (ADMINISTRADOR)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('administrador')
    ->middleware(['auth:sanctum', 'role:ADMINISTRADOR'])
    ->group(function () {
        // Roles, permisos y universidad
        Route::get   ('roles',               [RoleController::class, 'index']);
        Route::post  ('roles',               [RoleController::class, 'store']);
        Route::get   ('roles/{role}',        [RoleController::class, 'show']);
        Route::patch ('roles/{role}/rename', [RoleController::class, 'rename']);
        Route::delete('roles/{role}',        [RoleController::class, 'destroy']);

        Route::get   ('permissions',         [RoleController::class, 'permissionsIndex']);
        Route::post  ('roles/{role}/permissions/assign', [RoleController::class, 'assignPermissions']);
        Route::put   ('roles/{role}/permissions',        [RoleController::class, 'setPermissions']);
        Route::delete('roles/{role}/permissions',        [RoleController::class, 'revokePermissions']);

        Route::get('/universidad', [UniversidadController::class, 'show']);
        Route::put('/universidad', [UniversidadController::class, 'update']);
        Route::post('/universidad/logo', [UniversidadController::class, 'setLogo']);
        Route::post('/universidad/portada', [UniversidadController::class, 'setPortada']);

        // --- Módulo Académico (prefijado) ---
        Route::prefix('academico')
            ->as('administrador.academico.')
            ->scopeBindings()
            ->middleware('throttle:60,1')
            ->group(function () {

                Route::apiResource('facultades', FacultadApiController::class)
                    ->parameters(['facultades' => 'facultad'])
                    ->names('facultades');

                Route::apiResource('sedes', SedeApiController::class)
                    ->parameters(['sedes' => 'sede'])
                    ->names('sedes');

                // NUEVO: EP de una sede (incluye pivot con vigencias)
                Route::get('sedes/{sede}/escuelas', [SedeApiController::class, 'escuelas'])
                    ->whereNumber('sede')
                    ->name('sedes.escuelas');

                Route::apiResource('escuelas-profesionales', EscuelaProfesionalApiController::class)
                    ->parameters(['escuelas-profesionales' => 'escuela_profesional'])
                    ->names('escuelas');

                // Vinculaciones EP <-> Sede (admin)
                Route::post('escuelas-profesionales/{escuela_profesional}/sedes', [EscuelaProfesionalApiController::class, 'attachSede'])
                    ->whereNumber('escuela_profesional');

                Route::put('escuelas-profesionales/{escuela_profesional}/sedes/{sede}', [EscuelaProfesionalApiController::class, 'updateSedeVigencia'])
                    ->whereNumber('escuela_profesional')->whereNumber('sede');

                Route::delete('escuelas-profesionales/{escuela_profesional}/sedes/{sede}', [EscuelaProfesionalApiController::class, 'detachSede'])
                    ->whereNumber('escuela_profesional')->whereNumber('sede');
            });
    });

// ─────────────────────────────────────────────────────────────────────────────
// REPORTES HORAS
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('reportes/horas')->group(function () {
        Route::get('mias', [ReporteHorasController::class, 'miReporte'])
            ->name('reportes.horas.mias');

        Route::get('expedientes/{expediente}', [ReporteHorasController::class, 'expedienteReporte'])
            ->name('reportes.horas.expediente');
    });
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/ep-sedes/{epSedeId}/reportes/horas', [HorasPorPeriodoController::class, 'index']);
    Route::get('/ep-sedes/{epSedeId}/reportes/horas/export', [HorasPorPeriodoController::class, 'export']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get ('/ep-sedes/{epSedeId}/staff',            [EpSedeStaffController::class, 'current']);
    Route::post('/ep-sedes/{epSedeId}/staff/assign',     [EpSedeStaffController::class, 'assign']);
    Route::post('/ep-sedes/{epSedeId}/staff/unassign',   [EpSedeStaffController::class, 'unassign']);
    Route::post('/ep-sedes/{epSedeId}/staff/reinstate',  [EpSedeStaffController::class, 'reinstate']);
    Route::post('/ep-sedes/{epSedeId}/staff/delegate',   [EpSedeStaffController::class, 'delegate']);
    Route::get ('/ep-sedes/{epSedeId}/staff/history',    [EpSedeStaffController::class, 'history']);
});
