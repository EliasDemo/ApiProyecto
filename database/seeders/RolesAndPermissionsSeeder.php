<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Limpia caché de Spatie
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Usa el guard con el que se autentican tus usuarios (normalmente 'web' con Sanctum)
        $guard = 'web';

        // ─────────────────────────────────────────────────────────────
        // Permisos base (usados por servicios/controladores)
        // ─────────────────────────────────────────────────────────────
        $basePerms = [
            'ep.manage.ep_sede',
            'ep.manage.sede',
            'ep.manage.facultad',
            'ep.view.expediente',
        ];

        // ─────────────────────────────────────────────────────────────
        // Permisos específicos de gestión de staff
        // ─────────────────────────────────────────────────────────────
        $staffPerms = [
            'ep.staff.manage.coordinador', // crear/suspender COORDINADORES
            'ep.staff.manage.encargado',   // crear/suspender ENCARGADOS
        ];

        // ─────────────────────────────────────────────────────────────
        // Permisos VM (coinciden con los middleware de tus rutas)
        // ─────────────────────────────────────────────────────────────
        $vmPerms = [
            // Proyectos
            'vm.proyecto.niveles.read',
            'vm.proyecto.read',
            'vm.proyecto.create',
            'vm.proyecto.update',
            'vm.proyecto.delete',
            'vm.proyecto.publish',

            // Inscripciones / candidatos (gestión)
            'vm.proyecto.inscripciones.read',
            'vm.proyecto.candidatos.read',
            'vm.proyecto.inscripciones.batch.create', // 🆕 inscripción masiva de candidatos

            // Imágenes de proyecto
            'vm.proyecto.imagen.read',
            'vm.proyecto.imagen.create',
            'vm.proyecto.imagen.delete',

            // Procesos
            'vm.proceso.read',
            'vm.proceso.create',
            'vm.proceso.update',
            'vm.proceso.delete',
            'vm.proceso.calificar', // 🆕 calificar proceso de tipo EVALUACION / MIXTO

            // Sesiones
            'vm.sesion.batch.create',
            'vm.sesion.read',
            'vm.sesion.update',
            'vm.sesion.delete',

            // Eventos
            'vm.evento.read',
            'vm.evento.create',
            'vm.evento.update',
            'vm.evento.delete', // DELETE /vm/eventos/{evento}
            'vm.evento.candidatos.read',
            'vm.evento.inscripciones.read',

            // Inscripciones a eventos
            'vm.evento.inscripciones.read', // 🆕 ver inscritos del evento
            'vm.evento.candidatos.read',    // 🆕 ver candidatos elegibles al evento
            'vm.proyecto.inscripciones.mass-enroll',
            'vm.proyecto.inscripciones.seleccionados',


            // Categorías de eventos
            'vm.evento.categoria.read',
            'vm.evento.categoria.create',
            'vm.evento.categoria.update',
            'vm.evento.categoria.delete',

            // Imágenes de eventos
            'vm.evento.imagen.read',
            'vm.evento.imagen.create',
            'vm.evento.imagen.delete',

            // Agenda staff
            'vm.agenda.staff.read',

            // Asistencias (staff)
            'vm.asistencia.abrir_qr',
            'vm.asistencia.activar_manual',
            'vm.asistencia.checkin.manual',
            'vm.asistencia.participantes.read',
            'vm.asistencia.justificar.create',
            'vm.asistencia.read',
            'vm.asistencia.reporte.read',
            'vm.asistencia.validar',


        ];

        // Crear (o asegurar) todos los permisos con el guard correcto
        $allPerms = array_merge($basePerms, $staffPerms, $vmPerms);

        foreach ($allPerms as $perm) {
            Permission::firstOrCreate([
                'name'       => $perm,
                'guard_name' => $guard,
            ]);
        }

        // ─────────────────────────────────────────────────────────────
        // Roles
        // ─────────────────────────────────────────────────────────────
        $admin       = Role::firstOrCreate(['name' => 'ADMINISTRADOR', 'guard_name' => $guard]);
        $coordinador = Role::firstOrCreate(['name' => 'COORDINADOR',   'guard_name' => $guard]);
        $encargado   = Role::firstOrCreate(['name' => 'ENCARGADO',     'guard_name' => $guard]);
        $estudiante  = Role::firstOrCreate(['name' => 'ESTUDIANTE',    'guard_name' => $guard]);

        // ADMINISTRADOR: todo
        $admin->syncPermissions(Permission::all());

        // ENCARGADO = gestión completa VM + permisos base (sin gestión de staff)
        $encargado->syncPermissions(array_merge($basePerms, $vmPerms));

        // COORDINADOR = lectura VM + gestión de ENCARGADOS en sus EP-Sedes
        $coordinadorPerms = [
            // base
            'ep.manage.ep_sede',

            // staff (solo encargados)
            'ep.staff.manage.encargado',

            // proyectos (lectura)
            'vm.proyecto.niveles.read',
            'vm.proyecto.read',
            'vm.proyecto.inscripciones.read',
            'vm.proyecto.candidatos.read',
            'vm.proyecto.imagen.read',

            // procesos/sesiones (lectura)
            'vm.proceso.read',
            'vm.sesion.read',

            // eventos (lectura)
            'vm.evento.read',
            'vm.evento.imagen.read',
            'vm.evento.categoria.read',
            'vm.evento.inscripciones.read', // 🆕 ver inscritos de eventos
            'vm.evento.candidatos.read',    // 🆕 ver candidatos de eventos

            // agenda staff (lectura)
            'vm.agenda.staff.read',

            // asistencias (consulta/reportes/participantes)
            'vm.asistencia.read',
            'vm.asistencia.reporte.read',
            'vm.asistencia.participantes.read',
        ];
        $coordinador->syncPermissions($coordinadorPerms);

        // ESTUDIANTE: solo ver su expediente
        $estudiante->syncPermissions(['ep.view.expediente']);
    }
}
