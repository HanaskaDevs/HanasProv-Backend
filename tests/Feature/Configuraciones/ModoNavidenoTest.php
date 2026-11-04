<?php

namespace Tests\Feature\Configuraciones;

use App\Modules\Configuraciones\Models\Configuracion;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Tests\TestCase;

/**
 * Modo navideño (04-nov-2026): se lee sin sesión (decora también el login) y
 * solo Sistemas lo enciende o apaga.
 */
class ModoNavidenoTest extends TestCase
{
    public function test_sin_configurar_esta_apagado(): void
    {
        Configuracion::where('Clave', ConfiguracionService::CLAVE_MODO_NAVIDENO)->delete();

        $this->getJson('/api/public-config/modo-navideno')->assertOk()->assertExactJson(['activo' => false]);
    }

    public function test_sistemas_lo_enciende_y_cualquiera_lo_ve_sin_sesion(): void
    {
        $empresa = $this->crearEmpresa();
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Sistemas'), $empresa);

        $this->putJson('/api/configuraciones/modo-navideno', ['activo' => true], $cabeceras)
            ->assertOk()->assertJsonPath('activo', true);
        $this->getJson('/api/public-config/modo-navideno')->assertOk()->assertJsonPath('activo', true);

        $this->putJson('/api/configuraciones/modo-navideno', ['activo' => false], $cabeceras)
            ->assertOk()->assertJsonPath('activo', false);
        $this->getJson('/api/public-config/modo-navideno')->assertOk()->assertJsonPath('activo', false);
    }

    public function test_otros_roles_no_lo_cambian(): void
    {
        $empresa = $this->crearEmpresa();

        foreach (['Admin', 'Compras', 'Calidad'] as $rol) {
            $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, $rol), $empresa);
            $this->putJson('/api/configuraciones/modo-navideno', ['activo' => true], $cabeceras)->assertForbidden();
        }
    }

    public function test_pide_un_valor_si_o_no(): void
    {
        $empresa = $this->crearEmpresa();
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Sistemas'), $empresa);

        $this->putJson('/api/configuraciones/modo-navideno', ['activo' => 'quizas'], $cabeceras)->assertStatus(422);
    }
}
