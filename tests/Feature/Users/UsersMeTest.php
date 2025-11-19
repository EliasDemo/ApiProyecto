<?php
declare(strict_types=1);

namespace Tests\Feature\Users;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Test;

final class UsersMeTest extends ApiTestCase
{
    use AuthHelpers;

    #[Test]
    public function me_para_admin_devuelve_user_detail_sin_expediente_activo(): void
    {
        $headers = $this->loginAs('upeu.admin');

        $res = $this->getJson('/api/users/me', $headers);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)
                     ->has('user', fn (AssertableJson $user) =>
                         $user->where('username', 'upeu.admin')
                              ->hasAll([
                                  'roles', 'permissions', 'permissions_map',
                                  'profile_photo', 'profile_photo_url',
                              ])
                              ->where('rol_principal', 'ADMINISTRADOR')
                              ->where('expediente_activo', null)
                              ->etc()
                     )
                     ->etc()
            );
    }

    #[Test]
    public function me_para_estudiante_devuelve_expediente_activo_con_arbol_academico(): void
    {
        $headers = $this->loginAs('upeu.jorge');

        $res = $this->getJson('/api/users/me', $headers);

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('ok', true)
                     ->has('user', fn (AssertableJson $user) =>
                         $user->where('username', 'upeu.jorge')
                              ->has('permissions', fn ($p) => $p->etc())
                              ->has('permissions_map', fn ($pm) =>
                                  $pm->where('ep.view.expediente', true)->etc()
                              )
                              ->has('expediente_activo', fn (AssertableJson $exp) =>
                                  $exp->hasAll(['id','estado'])
                                      ->has('ep_sede', fn ($e) =>
                                          $e->hasAll(['id','vigente_desde','vigente_hasta'])->etc()
                                      )
                                      ->has('sede', fn ($s) =>
                                          $s->hasAll(['id','nombre','es_principal','esta_suspendida'])->etc()
                                      )
                                      ->has('escuela_profesional', fn ($ep) =>
                                          $ep->hasAll(['id','codigo','nombre'])->etc()
                                      )
                                      ->has('facultad', fn ($f) =>
                                          $f->hasAll(['id','codigo','nombre'])->etc()
                                      )
                                      ->has('universidad', fn ($u) =>
                                          $u->hasAll(['id','codigo','nombre','tipo_gestion','estado_licenciamiento'])->etc()
                                      )
                                      ->etc() // ← Permite campos extra como codigo_estudiante, grupo, matriculas, etc.
                              )
                              ->etc()
                     )
                     ->etc()
            );
    }
}
