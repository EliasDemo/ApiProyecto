<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

/**
 * Base para pruebas de API:
 * - Migra y siembra la BD de testing (usa tus seeders).
 * - Proporciona Faker si lo necesitas.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    /**
     * Ejecuta DatabaseSeeder antes de la suite/clase de pruebas.
     * (usa tus seeders: Roles/Permisos, Universidad, Académico, Demo, Matrículas)
     */
    protected bool $seed = true;
}
