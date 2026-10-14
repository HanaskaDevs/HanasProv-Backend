<?php

namespace Tests\Feature\Proveedores;

use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Tests\TestCase;

/**
 * GET /api/proveedores, GET /api/proveedores/{id} y POST /api/proveedores.
 *
 * El listado trae RUC, razón social y correo de TODOS los proveedores de la
 * empresa: hasta ahora el grupo de rutas solo pedía estar logueado con
 * empresa activa, así que un usuario Proveedor podía bajarse la lista de su
 * competencia. Estos tests fijan quién entra: listado y detalle para
 * Sistemas, Admin y Calidad; el alta solo para Sistemas y Admin (ver
 * ProveedorService).
 */
class AccesoListadoProveedoresTest extends TestCase
{
    public function test_un_proveedor_no_puede_listar_a_los_demas_proveedores(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa);
        [, $competidor] = $this->crearProveedorConUsuario($empresa);

        $respuesta = $this->getJson('/api/proveedores', $this->cabecerasComo($usuario, $empresa));

        $respuesta->assertForbidden();
        $respuesta->assertDontSee($competidor->Email);
        $respuesta->assertDontSee($competidor->Ruc);
    }

    public function test_un_proveedor_no_puede_ver_el_detalle_de_otro_proveedor(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa);
        [, $competidor] = $this->crearProveedorConUsuario($empresa);

        $this->getJson("/api/proveedores/{$competidor->Id_Proveedor}", $this->cabecerasComo($usuario, $empresa))
            ->assertForbidden();
    }

    public function test_un_proveedor_no_puede_dar_de_alta_proveedores(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa);
        $ruc = $this->rucFalso();

        $this->postJson('/api/proveedores', [
            'ruc' => $ruc,
            'razon_social' => 'Alta indebida',
            'email' => 'alta_indebida@test.local',
        ], $this->cabecerasComo($usuario, $empresa))->assertForbidden();

        $this->assertFalse(Proveedor::where('Ruc', $ruc)->exists());
    }

    /** Roles internos sin pantalla que use el listado. */
    public function test_compras_y_guardia_no_pueden_listar_proveedores(): void
    {
        $empresa = $this->crearEmpresa();
        $this->crearProveedorConUsuario($empresa);

        foreach (['Compras', 'Guardia'] as $rol) {
            $interno = $this->crearUsuarioInterno($empresa, $rol);

            $this->getJson('/api/proveedores', $this->cabecerasComo($interno, $empresa))
                ->assertForbidden();
        }
    }

    public function test_compras_y_guardia_no_pueden_ver_el_detalle_de_un_proveedor(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        foreach (['Compras', 'Guardia'] as $rol) {
            $interno = $this->crearUsuarioInterno($empresa, $rol);

            $this->getJson("/api/proveedores/{$proveedor->Id_Proveedor}", $this->cabecerasComo($interno, $empresa))
                ->assertForbidden();
        }
    }

    public function test_calidad_no_puede_dar_de_alta_proveedores(): void
    {
        $empresa = $this->crearEmpresa();
        $interno = $this->crearUsuarioInterno($empresa, 'Calidad');
        $ruc = $this->rucFalso();

        $this->postJson('/api/proveedores', [
            'ruc' => $ruc,
            'razon_social' => 'Alta de Calidad',
            'email' => 'alta_calidad@test.local',
        ], $this->cabecerasComo($interno, $empresa))->assertForbidden();

        $this->assertFalse(Proveedor::where('Ruc', $ruc)->exists());
    }

    public function test_admin_da_de_alta_un_proveedor_aspirante_en_su_empresa(): void
    {
        $empresa = $this->crearEmpresa();
        $interno = $this->crearUsuarioInterno($empresa, 'Admin');
        $ruc = $this->rucFalso();

        $this->postJson('/api/proveedores', [
            'ruc' => $ruc,
            'razon_social' => 'Alta de Admin',
            'email' => 'alta_admin@test.local',
        ], $this->cabecerasComo($interno, $empresa))->assertCreated();

        $creado = Proveedor::where('Ruc', $ruc)->firstOrFail();
        $this->assertSame((int) $empresa->Id_Empresa, (int) $creado->Id_Empresa);
        $this->assertSame(EstadoProveedor::ASPIRANTE, (int) $creado->Id_Estado_Proveedor);
    }

    public function test_sistemas_admin_y_calidad_listan_los_proveedores_de_su_empresa(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        foreach (['Sistemas', 'Admin', 'Calidad'] as $rol) {
            $interno = $this->crearUsuarioInterno($empresa, $rol);

            $this->getJson('/api/proveedores', $this->cabecerasComo($interno, $empresa))
                ->assertOk()
                ->assertJsonFragment(['id' => $proveedor->Id_Proveedor, 'email' => $proveedor->Email]);

            $this->getJson("/api/proveedores/{$proveedor->Id_Proveedor}", $this->cabecerasComo($interno, $empresa))
                ->assertOk()
                ->assertJsonPath('id', $proveedor->Id_Proveedor);
        }
    }

    /**
     * El detalle resolvía el {proveedor} por route model binding, sin mirar
     * la empresa: con el id de un proveedor de otra empresa del grupo
     * alcanzaba para verlo.
     */
    public function test_el_detalle_no_muestra_proveedores_de_otra_empresa(): void
    {
        $empresa = $this->crearEmpresa();
        $otraEmpresa = $this->crearEmpresa();
        [, $ajeno] = $this->crearProveedorConUsuario($otraEmpresa);
        $interno = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->getJson("/api/proveedores/{$ajeno->Id_Proveedor}", $this->cabecerasComo($interno, $empresa))
            ->assertNotFound();
    }
}
