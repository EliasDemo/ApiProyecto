<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SeederController extends Controller
{
    public function run()
    {
        // Ejecutar TODOS los seeders registrados en DatabaseSeeder
        Artisan::call('db:seed', [
            '--force' => true, // necesario en producción
        ]);

        return response()->json([
            'message' => 'Seeders ejecutados correctamente',
            'output'  => Artisan::output(),
        ]);
    }

    public function runUserSeeder()
    {
        // Ejemplo: ejecutar solo un seeder específico
        Artisan::call('db:seed', [
            '--class' => 'UserSeeder',
            '--force' => true,
        ]);

        return response()->json([
            'message' => 'UserSeeder ejecutado correctamente',
            'output'  => Artisan::output(),
        ]);
    }
}
