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
use App\Http\Controllers\Api\Alumno\DashboardController;
use App\Http\Controllers\Api\Reportes\ReporteAvanceController;
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
use App\Http\Controllers\Api\Vm\AsistenciasController as VmAsistenciasController;
use App\Http\Controllers\Api\Vm\CategoriaEventoController;
use App\Http\Controllers\Api\Vm\EventoFullController;
use App\Http\Controllers\Api\Vm\EventoImagenController;
use App\Http\Controllers\Api\Vm\ImportHorasHistoricasController;
use App\Http\Controllers\Api\Vm\InscripcionEventoController;
use App\Http\Controllers\Api\Vm\ProyectoFullController;
use App\Http\Controllers\SeederController;

// ─────────────────────────────────────────────────────────────────────────────
// AUTENTICACIÓN Y USUARIOS
// ─────────────────────────────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->get('/user', fn (Request $r) => $r->user());

Route::prefix('auth')->group(function () {
    Route::post('/lookup', [AuthController::class, 'lookup']);
    Route::post('/login',  [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum'])
    ->prefix('users')
    ->group(function () {
        // Perfil actual
        Route::get('me', [UserController::class, 'me']);
        Route::put('me', [UserController::class, 'updateMe']);                 // actualizar perfil
        Route::put('me/password', [UserController::class, 'updateMyPassword']); // cambiar contraseña

        // Sesiones
        Route::get('me/sessions', [UserController::class, 'sessions']);            // listar sesiones
        Route::delete('me/sessions/{id}', [UserController::class, 'destroySession']); // cerrar sesión

        // Buscar por username
        Route::get('by-username/{username}', [UserController::class, 'showByUsername']);
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
    // Listado de proyectos visibles para el alumno (según EP-SEDE / estado / etc.)
    Route::get('/proyectos/alumno', [ProyectoController::class, 'indexAlumno'])
        ->name('vm.proyectos.index-alumno');


    // Alumno se inscribe a un PROYECTO
    // POST /api/vm/proyectos/{proyecto}/inscribirse
    Route::post('/proyectos/{proyecto}/inscribirse', [InscripcionProyectoController::class, 'inscribirProyecto'])
        ->whereNumber('proyecto')
        ->name('vm.proyectos.inscribirse');

    // 🆕 Alumno se inscribe a un EVENTO (tipo LIBRE, sin ciclos)
    // - Valida EP-SEDE, ventana de inscripción, cupo y estado del evento
    // POST /api/vm/eventos/{evento}/inscribirse
    Route::post('/eventos/{evento}/inscribirse', [InscripcionEventoController::class, 'inscribirEvento'])
        ->whereNumber('evento')
        ->name('vm.eventos.inscribirse');

    Route::get('/mis-eventos', [InscripcionEventoController::class, 'misEventos'])
        ->name('vm.eventos.mis-eventos');

    // Agenda del alumno (proyectos + eventos + sesiones)
    Route::get('/alumno/agenda', [AgendaController::class, 'agendaAlumno'])
        ->name('vm.alumno.agenda');

    // Check-in por QR (alumno)
    Route::post('/sesiones/{sesion}/check-in/qr', [AsistenciasController::class, 'checkInPorQr'])
        ->whereNumber('sesion')
        ->name('vm.sesiones.checkin-qr');

    // Detalle de un proyecto (vista alumno)
    Route::get('/alumno/proyectos/{proyecto}', [ProyectoController::class, 'show'])
        ->whereNumber('proyecto')
        ->name('vm.alumno.proyectos.show');
});

/**
 * 2️⃣ RUTAS DE GESTIÓN (con permisos por endpoint)
 */
Route::middleware(['auth:sanctum'])->prefix('vm')->group(function () {

    // ── Proyectos: niveles disponibles
    Route::get('/proyectos/niveles-disponibles', [ProyectoController::class, 'nivelesDisponibles'])
        ->middleware('permission:vm.proyecto.niveles.read')
        ->name('vm.proyectos.niveles-disponibles');

    // ── Proyectos: CRUD
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

    // ── Inscripciones de proyectos (STAFF)
    // Listar inscritos de un proyecto
    Route::get('/proyectos/{proyecto}/inscritos', [InscripcionProyectoController::class, 'listarInscritos'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.inscripciones.read');

    // Listar candidatos a un proyecto (elegibles / no inscritos)
    Route::get('/proyectos/{proyecto}/candidatos', [InscripcionProyectoController::class, 'listarCandidatos'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.candidatos.read');

    // 🆕 Inscribir masivamente a TODOS los candidatos elegibles en un proyecto
    // - Usa la misma lógica que listarCandidatos (EP-SEDE, ciclo, pendientes VINCULADO, etc.)
    // - Solo roles con permiso específico
    // POST /api/vm/proyectos/{proyecto}/inscribir-todos-candidatos
    // Permiso: vm.proyecto.inscripciones.mass-enroll
    Route::post('/proyectos/{proyecto}/inscribir-todos-candidatos', [InscripcionProyectoController::class, 'inscribirTodosCandidatos'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.inscripciones.mass-enroll')
        ->name('vm.proyectos.inscribir-todos-candidatos');

    Route::post('/proyectos/{proyecto}/inscribir-candidatos-seleccionados', [InscripcionProyectoController::class, 'inscribirCandidatosSeleccionados'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.inscripciones.seleccionados')
        ->name('vm.proyectos.inscribir-candidatos-seleccionados');

    // ── Imágenes de proyecto
    Route::get('/proyectos/{proyecto}/imagenes', [ProyectoImagenController::class, 'index'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.imagen.read');

    Route::post('/proyectos/{proyecto}/imagenes', [ProyectoImagenController::class, 'store'])
        ->whereNumber('proyecto')
        ->middleware('permission:vm.proyecto.imagen.create');

    Route::delete('/proyectos/{proyecto}/imagenes/{imagen}', [ProyectoImagenController::class, 'destroy'])
        ->whereNumber('proyecto')->whereNumber('imagen')
        ->middleware('permission:vm.proyecto.imagen.delete');

    // ── Procesos y sesiones
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

    // ── Eventos: CRUD
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

    /** DELETE evento (solo PLANIFICADO, lógica en el controlador) */
    Route::delete('/eventos/{evento}', [EventoController::class, 'destroy'])
        ->whereNumber('evento')
        ->middleware('permission:vm.evento.delete')
        ->name('vm.eventos.destroy');

    // ── Inscripciones de eventos (STAFF)
    // Listar participantes inscritos a un evento
    // GET /api/vm/eventos/{evento}/inscritos
    // Permiso: vm.evento.inscripciones.read
    Route::get('/eventos/{evento}/inscritos', [InscripcionEventoController::class, 'listarInscritos'])
        ->whereNumber('evento')
        ->middleware('permission:vm.evento.inscripciones.read')
        ->name('vm.eventos.inscritos');

    // Listar candidatos a un evento (expedientes ACTIVO en EP-SEDE, no inscritos aún)
    // GET /api/vm/eventos/{evento}/candidatos
    // Permiso: vm.evento.candidatos.read
    Route::get('/eventos/{evento}/candidatos', [InscripcionEventoController::class, 'listarCandidatos'])
        ->whereNumber('evento')
        ->middleware('permission:vm.evento.candidatos.read')
        ->name('vm.eventos.candidatos');

    /** Categorías de evento (CRUD) */
    /** Categorías de evento (CRUD) */
    Route::get('/eventos/categorias', [CategoriaEventoController::class, 'categoriasIndex'])
        ->middleware('permission:vm.evento.categoria.read')
        ->name('vm.eventos.categorias.index');

    Route::post('/eventos/categorias', [CategoriaEventoController::class, 'categoriasStore'])
        ->middleware('permission:vm.evento.categoria.create')
        ->name('vm.eventos.categorias.store');

    Route::put('/eventos/categorias/{categoria}', [CategoriaEventoController::class, 'categoriasUpdate'])
        ->whereNumber('categoria')
        ->middleware('permission:vm.evento.categoria.update')
        ->name('vm.eventos.categorias.update');

    Route::delete('/eventos/categorias/{categoria}', [CategoriaEventoController::class, 'categoriasDestroy'])
        ->whereNumber('categoria')
        ->middleware('permission:vm.evento.categoria.delete')
        ->name('vm.eventos.categorias.destroy');

    /** Imágenes de evento */
    Route::get('/eventos/{evento}/imagenes', [EventoImagenController::class, 'index'])
        ->whereNumber('evento')
        ->middleware('permission:vm.evento.imagen.read');

    Route::post('/eventos/{evento}/imagenes', [EventoImagenController::class, 'store'])
        ->whereNumber('evento')
        ->middleware('permission:vm.evento.imagen.create');

    Route::delete('/eventos/{evento}/imagenes/{imagen}', [EventoImagenController::class, 'destroy'])
        ->whereNumber('evento')->whereNumber('imagen')
        ->middleware('permission:vm.evento.imagen.delete');

    // ── Agenda staff
    Route::get('/staff/agenda', [AgendaController::class, 'agendaStaff'])
        ->middleware('permission:vm.agenda.staff.read')
        ->name('vm.staff.agenda');

    // ── Asistencias / QR
    Route::post('/sesiones/{sesion}/qr', [VmAsistenciasController::class, 'generarQr'])
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

    // Calificar (EVALUACION / MIXTO)
    Route::post('/procesos/{proceso}/calificar', [AsistenciasController::class, 'calificarEvaluacion'])
        ->whereNumber('proceso')
        ->middleware('permission:vm.proceso.calificar')
        ->name('vm.procesos.calificar');
});

// ─────────────────────────────────────────────────────────────────────────────
// MATRÍCULAS
// ─────────────────────────────────────────────────────────────────────────────

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

        // ── Flujo manual
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

                // EP de una sede (incluye pivot con vigencias)
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

        // Resumen por proyecto (suma vm_proyecto + vm_proceso → proyecto)
        Route::get('mias/por-proyecto', [ReporteAvanceController::class, 'miAvancePorProyecto'])
            ->name('reportes.horas.mias.por_proyecto');
    });
});

// Con parámetro: EP-Sede explícito
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/ep-sedes/{epSedeId}/reportes/horas',        [HorasPorPeriodoController::class, 'index']);
    Route::get('/ep-sedes/{epSedeId}/reportes/horas/export', [HorasPorPeriodoController::class, 'export']);
});

// Sin parámetro: EP-Sede resuelta desde el usuario
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/reportes/horas',        [HorasPorPeriodoController::class, 'indexAuto']);
    Route::get('/reportes/horas/export', [HorasPorPeriodoController::class, 'exportAuto']);
});

// Import histórico de horas
Route::middleware(['auth:sanctum'])->group(function () {
    // Importar
    Route::post('/vm/import/historico-horas', [ImportHorasHistoricasController::class, 'import'])
         ->name('vm.import.historico_horas');

    // Descargar plantilla
    Route::get('/vm/import/historico-horas/plantilla', [ImportHorasHistoricasController::class, 'template'])
         ->name('vm.import.historico_horas.plantilla');

    // Estado (para mostrar “aún no hay horas” en el front)
    Route::get('/vm/import/historico-horas/status', [ImportHorasHistoricasController::class, 'status'])
         ->name('vm.import.historico_horas.status');
});

// ─────────────────────────────────────────────────────────────────────────────
// EP-SEDE STAFF
// ─────────────────────────────────────────────────────────────────────────────

Route::middleware(['auth:sanctum'])->group(function () {

    // EP-Sede: Staff – contexto global del panel
    // GET /api/ep-sedes/staff/context
    Route::get('ep-sedes/staff/context', [EpSedeStaffController::class, 'context']);

    // EP-Sede: Staff (por EP-Sede)
    // Base: /api/ep-sedes/{epSedeId}/staff
    Route::prefix('ep-sedes/{epSedeId}/staff')->group(function () {

        // Staff actual
        Route::get('/', [EpSedeStaffController::class, 'current']);

        // Historial
        Route::get('/history', [EpSedeStaffController::class, 'history']);

        // Lookup por email
        Route::get('/lookup', [EpSedeStaffController::class, 'lookupByEmail']);

        // Asignar usuario existente
        Route::post('/assign', [EpSedeStaffController::class, 'assign']);

        // Desasignar
        Route::post('/unassign', [EpSedeStaffController::class, 'unassign']);

        // Reincorporar
        Route::post('/reinstate', [EpSedeStaffController::class, 'reinstate']);

        // Delegar encargado interino
        Route::post('/delegate', [EpSedeStaffController::class, 'delegate']);

        // Crear usuario + expediente + asignar
        Route::post('/create-and-assign', [EpSedeStaffController::class, 'createAndAssign']);
    });
});




// Rutas alumno (dashboard)
Route::middleware('auth:sanctum')
    ->prefix('alumno')
    ->group(function () {
        Route::get('feed', [DashboardController::class, 'index']);
    });

// Rutas VM (eventos)
Route::middleware('auth:sanctum')
    ->prefix('vm')
    ->group(function () {
        Route::post('eventos/{evento}/inscribirse', [InscripcionEventoController::class, 'inscribirEvento']);
        Route::get('eventos/mis', [InscripcionEventoController::class, 'misEventos']);
        Route::get('eventos/{evento}/inscritos', [InscripcionEventoController::class, 'listarInscritos']);
        Route::get('eventos/{evento}/candidatos', [InscripcionEventoController::class, 'listarCandidatos']);
    });

// Rutas VM (proyectos)
Route::middleware('auth:sanctum')
    ->prefix('vm')
    ->group(function () {
        Route::post('proyectos/{proyecto}/inscribirse', [InscripcionProyectoController::class, 'inscribirProyecto']);
        // ...
    });
