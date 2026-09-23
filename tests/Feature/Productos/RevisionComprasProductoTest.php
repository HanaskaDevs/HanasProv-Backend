<?php

namespace Tests\Feature\Productos;

use App\Modules\Ficha_Productos\Mail\AvisoProductoMail;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Proveedores\Services\CalificacionProveedorService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Circuito de aprobación de productos en dos pasos (23-sep-2026):
 *
 *     proveedor envía  ->  COMPRAS  ->  Calidad  ->  aprobado
 *
 * Lo que estos tests protegen es que el paso de Compras no se pueda
 * saltear: si Calidad pudiera aprobar un producto recién enviado, el
 * circuito nuevo quedaría de adorno sin que nadie se entere.
 */
class RevisionComprasProductoTest extends TestCase
{
    private function productoEsperandoCompras($proveedor): Producto
    {
        return Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => UnidadPresentacion::firstOrFail()->Id_Unidad_Presentacion,
            'Nombre_Producto' => 'PRODUCTO EN REVISION',
            'Activo' => 1,
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Pendiente',
            'Etapa_Aprobacion' => Producto::ETAPA_COMPRAS,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function test_compras_ve_en_su_bandeja_lo_que_el_proveedor_envio(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $producto = $this->productoEsperandoCompras($proveedor);

        $respuesta = $this->getJson('/api/productos-revision', $this->cabecerasComo($compras, $empresa));

        $respuesta->assertOk();
        $ids = collect($respuesta->json())->pluck('id_producto');
        $this->assertTrue($ids->contains($producto->Id_Producto));
    }

    public function test_calidad_no_puede_aprobar_lo_que_compras_todavia_no_miro(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $producto = $this->productoEsperandoCompras($proveedor);

        // ESTE es el corazón del cambio: sin esta barrera, el paso de
        // Compras sería decorativo.
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(CalificacionProveedorService::class)->calificarProducto(
            $calidad, $empresa->Id_Empresa, $producto->Id_Producto, aprobado: true, observacion: null
        );
    }

    public function test_compras_aprueba_y_recien_ahi_calidad_puede(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $producto = $this->productoEsperandoCompras($proveedor);

        $this->postJson(
            "/api/productos-revision/{$producto->Id_Producto}/aprobar",
            [],
            $this->cabecerasComo($compras, $empresa)
        )->assertOk();

        $this->assertSame(Producto::ETAPA_CALIDAD, $producto->fresh()->Etapa_Aprobacion);
        // Sigue Pendiente: Compras no aprueba el producto, lo deja pasar.
        $this->assertSame('Pendiente', $producto->fresh()->Estado_Calificacion);

        // Y a Calidad le llegó el aviso.
        Mail::assertQueued(AvisoProductoMail::class);

        // Ahora Calidad sí puede resolver.
        app(CalificacionProveedorService::class)->calificarProducto(
            $calidad, $empresa->Id_Empresa, $producto->Id_Producto, aprobado: true, observacion: null
        );

        $this->assertSame('Aprobado', $producto->fresh()->Estado_Calificacion);
    }

    public function test_rechazar_exige_motivo_y_devuelve_el_producto_al_proveedor(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        $producto = $this->productoEsperandoCompras($proveedor);
        $cabeceras = $this->cabecerasComo($compras, $empresa);

        // Sin motivo no se puede: es el único texto que va a recibir el proveedor.
        $this->postJson("/api/productos-revision/{$producto->Id_Producto}/rechazar", [], $cabeceras)
            ->assertStatus(422)
            ->assertJsonValidationErrors('observacion');

        $this->postJson(
            "/api/productos-revision/{$producto->Id_Producto}/rechazar",
            ['observacion' => 'La ficha técnica no corresponde al producto declarado.'],
            $cabeceras
        )->assertOk();

        $actualizado = $producto->fresh();

        $this->assertSame('Rechazado', $actualizado->Estado_Calificacion);
        // La etapa queda en Compras: es lo que le dice al proveedor QUIÉN
        // se lo rechazó.
        $this->assertSame(Producto::ETAPA_COMPRAS, $actualizado->Etapa_Aprobacion);
        $this->assertStringContainsString('ficha técnica', (string) $actualizado->Comentario_Calificacion);
        // El producto NO se borró: conserva sus documentos para que el
        // proveedor corrija y reenvíe.
        $this->assertTrue((bool) $actualizado->Activo);
        // Y queda editable para él.
        $this->assertTrue((bool) $proveedor->fresh()->Correcciones_Pendientes_Productos);

        Mail::assertQueued(AvisoProductoMail::class);
    }

    public function test_eliminar_lo_saca_del_catalogo_sin_borrar_el_historial(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        $producto = $this->productoEsperandoCompras($proveedor);

        $this->postJson(
            "/api/productos-revision/{$producto->Id_Producto}/eliminar",
            ['observacion' => 'Este producto no corresponde al rubro que tiene aprobado.'],
            $this->cabecerasComo($compras, $empresa)
        )->assertOk();

        $eliminado = $producto->fresh();

        // Baja lógica: la fila sigue, con el motivo, y los documentos que
        // subió el proveedor no se tiran.
        $this->assertNotNull($eliminado);
        $this->assertFalse((bool) $eliminado->Activo);
        $this->assertStringContainsString('no corresponde', (string) $eliminado->Comentario_Calificacion);

        Mail::assertQueued(AvisoProductoMail::class);
    }

    public function test_calidad_no_entra_a_la_bandeja_de_compras(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->getJson('/api/productos-revision', $this->cabecerasComo($calidad, $empresa))
            ->assertForbidden();
    }

    public function test_admin_puede_resolver_las_dos_etapas(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        $producto = $this->productoEsperandoCompras($proveedor);

        // Pedido explícito del usuario: "admin pueden aprobar tanto en el
        // proceso de compras como en el de calidad".
        $this->postJson(
            "/api/productos-revision/{$producto->Id_Producto}/aprobar",
            [],
            $this->cabecerasComo($admin, $empresa)
        )->assertOk();

        app(CalificacionProveedorService::class)->calificarProducto(
            $admin, $empresa->Id_Empresa, $producto->Id_Producto, aprobado: true, observacion: null
        );

        $this->assertSame('Aprobado', $producto->fresh()->Estado_Calificacion);
    }

    public function test_un_producto_ya_en_calidad_no_se_resuelve_de_nuevo_desde_compras(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        $producto = $this->productoEsperandoCompras($proveedor);
        $producto->forceFill(['Etapa_Aprobacion' => Producto::ETAPA_CALIDAD])->save();

        $this->postJson(
            "/api/productos-revision/{$producto->Id_Producto}/aprobar",
            [],
            $this->cabecerasComo($compras, $empresa)
        )->assertStatus(422);
    }
}
