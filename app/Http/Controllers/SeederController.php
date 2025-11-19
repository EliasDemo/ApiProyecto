<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SeederController extends Controller
{
    /**
     * Ejecuta TODOS los seeders de DatabaseSeeder.
     */
    public function run()
    {
        Artisan::call('db:seed', [
            '--force' => true,
        ]);

        return response()->json([
            'message' => 'Seeders ejecutados correctamente',
            'output'  => Artisan::output(),
        ]);
    }

    /**
     * Ejecuta SOLO el UserSeeder.
     */
    public function runUserSeeder()
    {
        Artisan::call('db:seed', [
            '--class' => 'UserSeeder',
            '--force' => true,
        ]);

        return response()->json([
            'message' => 'UserSeeder ejecutado correctamente',
            'output'  => Artisan::output(),
        ]);
    }

    /**
     * Ejecuta php artisan storage:link
     */
    public function runStorageLink()
    {
        Artisan::call('storage:link');

        return response()->json([
            'message' => 'storage:link ejecutado correctamente',
            'output'  => Artisan::output(),
        ]);
    }

    /**
     * Ejecuta cualquier comando personalizado (opcional).
     * EJ: { "command": "cache:clear" }
     */
    public function runAnyCommand(Request $request)
    {
        $request->validate([
            'command' => 'required|string',
        ]);

        Artisan::call($request->command);

        return response()->json([
            'message' => "Comando '{$request->command}' ejecutado correctamente",
            'output'  => Artisan::output(),
        ]);
    }
}
