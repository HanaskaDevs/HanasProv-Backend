<?php

namespace Tests\Feature\Productos;

use App\Modules\Ficha_Productos\Mail\AvisoProductoMail;
use App\Modules\Ficha_Productos\Mail\PendientesCalidadMail;
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
    private function productoEsperandoCompras($proveedor, string $nombre = 'PRODUCTO EN REVISION'): Producto
    {
        return Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => UnidadPresentacion::firstOrFail()->Id_Unidad_Presentacion,
            'Nombre_Producto' => $nombre,
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

        // Y a Calidad le llegó el aviso, con el panorama del proveedor.
        Mail::assertQueued(PendientesCalidadMail::class, function (PendientesCalidadMail $correo) use ($proveedor, $calidad) {
            return $correo->hasTo($calidad->Email)
                && str_contains($correo->urlAccion, 'calificar='.$proveedor->Id_Proveedor)
                && $correo->panorama['productos']['recien_aprobados'] === ['PRODUCTO EN REVISION'];
        });

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

    /** Calidad tiene acceso total a la bandeja de Compras desde el 16-oct-2026. */
    public function test_calidad_entra_a_la_bandeja_de_compras(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $producto = $this->productoEsperandoCompras($proveedor);

        $this->getJson('/api/productos-revision', $this->cabecerasComo($calidad, $empresa))
            ->assertOk()
            ->assertJsonFragment(['id_producto' => $producto->Id_Producto]);
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

    /**
     * Selección múltiple: Compras aprueba varios de una vez y a Calidad le
     * llega UN correo por proveedor, no uno por producto.
     */
    public function test_aprobar_varios_manda_un_solo_correo_por_proveedor(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedorA] = $this->crearProveedorConUsuario($empresa);
        [, $proveedorB] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        $this->crearUsuarioInterno($empresa, 'Calidad');

        $a1 = $this->productoEsperandoCompras($proveedorA, 'ACEITE');
        $a2 = $this->productoEsperandoCompras($proveedorA, 'ARROZ');
        $a3 = $this->productoEsperandoCompras($proveedorA, 'AZUCAR');
        $b1 = $this->productoEsperandoCompras($proveedorB, 'BANANO');

        $this->postJson(
            '/api/productos-revision/aprobar-varios',
            ['productos' => [$a1->Id_Producto, $a2->Id_Producto, $a3->Id_Producto, $b1->Id_Producto]],
            $this->cabecerasComo($compras, $empresa)
        )->assertOk()->assertJsonPath('aprobados', 4);

        foreach ([$a1, $a2, $a3, $b1] as $producto) {
            $this->assertSame(Producto::ETAPA_CALIDAD, $producto->fresh()->Etapa_Aprobacion);
        }

        // 4 productos de 2 proveedores -> 2 correos, no 4.
        Mail::assertQueuedCount(2);
        Mail::assertQueued(PendientesCalidadMail::class, fn (PendientesCalidadMail $c) => count($c->panorama['productos']['recien_aprobados']) === 3
            && str_contains($c->urlAccion, 'calificar='.$proveedorA->Id_Proveedor));
    }

    /**
     * Todo o nada: si uno de los seleccionados ya lo resolvió otra persona,
     * no se aprueba ninguno y no sale ningún correo.
     */
    public function test_aprobar_varios_no_aprueba_ninguno_si_uno_ya_no_esta_en_compras(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $libre = $this->productoEsperandoCompras($proveedor, 'LIBRE');
        $yaResuelto = $this->productoEsperandoCompras($proveedor, 'YA RESUELTO');
        $yaResuelto->forceFill(['Etapa_Aprobacion' => Producto::ETAPA_CALIDAD])->save();

        $this->postJson(
            '/api/productos-revision/aprobar-varios',
            ['productos' => [$libre->Id_Producto, $yaResuelto->Id_Producto]],
            $this->cabecerasComo($compras, $empresa)
        )->assertStatus(422)->assertJsonValidationErrors('productos');

        $this->assertSame(Producto::ETAPA_COMPRAS, $libre->fresh()->Etapa_Aprobacion);
        Mail::assertNothingQueued();
    }

    /**
     * Si Calidad aprueba en la etapa de Compras, siguen siendo dos pasos (el
     * producto pasa a la etapa de Calidad), pero no se le manda a Calidad el
     * aviso de pendientes: lo acaba de mover ella misma.
     */
    public function test_calidad_aprueba_en_la_etapa_de_compras_sin_avisarse_a_si_misma(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $producto = $this->productoEsperandoCompras($proveedor);

        $this->postJson(
            '/api/productos-revision/aprobar-varios',
            ['productos' => [$producto->Id_Producto]],
            $this->cabecerasComo($calidad, $empresa)
        )->assertOk();

        $this->assertSame(Producto::ETAPA_CALIDAD, $producto->fresh()->Etapa_Aprobacion);
        Mail::assertNotQueued(PendientesCalidadMail::class);
    }

    /**
     * El correo cuenta la ficha y los documentos con los mismos estados que
     * la pantalla de Detalle de Proveedores, y suma los productos que ya
     * estaban esperando a Calidad de antes.
     */
    public function test_el_correo_a_calidad_trae_ficha_documentos_y_productos_pendientes(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $proveedor->forceFill([
            'Porcentaje_Completado_Ficha' => 100,
            'Fecha_Registro_Documentacion' => null,
        ])->save();

        $deAntes = $this->productoEsperandoCompras($proveedor, 'YA ESTABA EN CALIDAD');
        $deAntes->forceFill(['Etapa_Aprobacion' => Producto::ETAPA_CALIDAD])->save();
        $recien = $this->productoEsperandoCompras($proveedor, 'RECIEN APROBADO');

        $panorama = app(\App\Modules\Ficha_Productos\Services\AvisoProductosService::class)
            ->panoramaDelProveedor($proveedor->fresh(), collect([$recien]));

        $this->assertSame('pendiente', $panorama['ficha']['estado']);
        $this->assertSame('incompleta', $panorama['documentos']['estado']);
        $this->assertSame(['RECIEN APROBADO'], $panorama['productos']['recien_aprobados']);
        $this->assertSame(['YA ESTABA EN CALIDAD'], $panorama['productos']['otros_pendientes']);
    }
}
