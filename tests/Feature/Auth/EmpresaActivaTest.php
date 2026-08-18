<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * El aislamiento entre empresas es la garantía más importante de todo el
 * portal: un mismo usuario puede tener acceso a varias empresas, y la
 * empresa activa llega en el header X-Empresa-Activa, que lo pone el
 * navegador -> o sea, NO es de fiar. Si el middleware EmpresaActiva no
 * verificara ese header contra la tabla pivote, cambiar un número en la
 * petición alcanzaría para leer los datos de otra empresa.
 */
class EmpresaActivaTest extends TestCase
{
    public function test_un_usuario_ve_los_datos_de_la_empresa_a_la_que_si_tiene_acceso(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $respuesta = $this->getJson('/api/usuarios/internos', $this->cabecerasComo($usuario, $empresa));

        $respuesta->assertOk();
    }

    public function test_no_puede_leer_otra_empresa_cambiando_el_header(): void
    {
        $empresaPropia = $this->crearEmpresa();
        $empresaAjena = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresaPropia, 'Sistemas');

        // Token y sesión válidos, pero pidiendo datos de una empresa donde
        // este usuario no tiene ningún vínculo.
        $cabeceras = $this->cabecerasComo($usuario, $empresaPropia);
        $cabeceras['X-Empresa-Activa'] = (string) $empresaAjena->Id_Empresa;

        $respuesta = $this->getJson('/api/usuarios/internos', $cabeceras);

        $respuesta->assertForbidden()
            ->assertJson(['message' => 'No tiene acceso a la empresa seleccionada.']);
    }

    public function test_un_vinculo_inactivo_no_da_acceso(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa, 'Sistemas');

        // Le quitan el acceso (soft-delete del pivote, la fila nunca se borra).
        $usuario->usuarioEmpresas()->where('Id_Empresa', $empresa->Id_Empresa)
            ->update(['Activo' => false]);

        $respuesta = $this->getJson('/api/usuarios/internos', $this->cabecerasComo($usuario, $empresa));

        $respuesta->assertForbidden();
    }

    public function test_sin_token_no_entra(): void
    {
        $this->getJson('/api/usuarios/internos')->assertUnauthorized();
    }

    /**
     * El rol se resuelve por empresa (pivote Usuario_Empresa), no por un
     * campo del usuario -> el panel de usuarios internos es solo de
     * Sistemas, así que un Compras con acceso a la MISMA empresa no entra.
     */
    public function test_el_rol_se_respeta_dentro_de_la_empresa(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->getJson('/api/usuarios/internos', $this->cabecerasComo($compras, $empresa))
            ->assertForbidden();
    }
}
