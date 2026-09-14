<?php

namespace Tests\Feature\Productos;

use App\Modules\Ficha_Productos\Models\GrupoProducto;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use Tests\TestCase;

/**
 * Ficha de productos manejada por el COMPRADOR (rol Compras) sobre un
 * proveedor cualquiera de su empresa, y el campo "grupo de producto".
 *
 * Lo que se está protegiendo acá es que abrir estos endpoints al personal
 * interno no haya abierto de paso dos puertas que antes estaban cerradas
 * por construcción: leer productos de OTRA empresa, y saltearse el
 * circuito de aprobación que sí cumple el proveedor.
 */
class ProductosDeProveedorTest extends TestCase
{
    private function idUnidad(): int
    {
        return (int) UnidadPresentacion::where('Activo', 1)->value('Id_Unidad_Presentacion');
    }

    /** @return array<string, mixed> */
    private function datosProducto(array $extra = []): array
    {
        return [
            'nombre_producto' => 'Producto de prueba',
            'id_unidad_presentacion' => $this->idUnidad(),
            'precio' => 10.50,
            ...$extra,
        ];
    }

    public function test_compras_ve_los_proveedores_de_su_empresa_con_sus_conteos(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => 'YA CARGADO',
            'Activo' => 1,
            'Bloqueado' => 0,
            'Fecha_Creacion' => now(),
        ]);

        $respuesta = $this->getJson('/api/productos-proveedor/proveedores', $this->cabecerasComo($compras, $empresa));

        $respuesta->assertOk();

        $fila = collect($respuesta->json())->firstWhere('id_proveedor', $proveedor->Id_Proveedor);

        $this->assertNotNull($fila, 'El proveedor de la empresa debería aparecer en el selector.');
        $this->assertSame(1, $fila['total_productos']);
        // Cargado pero todavía sin mandar a calificar.
        $this->assertSame(1, $fila['productos_pendientes']);
        $this->assertSame(0, $fila['productos_aprobados']);
    }

    public function test_compras_crea_un_producto_a_nombre_del_proveedor(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $respuesta = $this->postJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            $this->datosProducto(['nombre_producto' => 'cargado por el comprador']),
            $this->cabecerasComo($compras, $empresa)
        );

        $respuesta->assertCreated();

        $producto = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)->latest('Id_Producto')->first();

        $this->assertNotNull($producto);
        // El nombre se normaliza a mayúsculas venga de donde venga.
        $this->assertSame('CARGADO POR EL COMPRADOR', $producto->Nombre_Producto);
        // Queda a nombre del proveedor, pero con la trazabilidad de quién lo
        // cargó. assertEquals y no assertSame: el driver de SQL Server
        // devuelve estos enteros como string ("4580" en vez de 4580), un
        // detalle del driver que ya está documentado en el frontend.
        $this->assertEquals($proveedor->Id_Proveedor, $producto->Id_Proveedor);
        $this->assertEquals($compras->Id_Usuario, $producto->Creado_Por);
        // Nace igual que uno del proveedor: editable y sin calificar.
        $this->assertFalse((bool) $producto->Bloqueado);
        $this->assertNull($producto->Estado_Calificacion);
    }

    public function test_compras_no_puede_tocar_un_proveedor_de_otra_empresa(): void
    {
        $empresaPropia = $this->crearEmpresa();
        $empresaAjena = $this->crearEmpresa();
        [, $proveedorAjeno] = $this->crearProveedorConUsuario($empresaAjena);
        $compras = $this->crearUsuarioInterno($empresaPropia, 'Compras');

        // El id existe, pero es de otra empresa -> no debe filtrarse ni
        // siquiera la existencia del proveedor.
        $this->getJson(
            "/api/productos-proveedor/{$proveedorAjeno->Id_Proveedor}",
            $this->cabecerasComo($compras, $empresaPropia)
        )->assertNotFound();

        $this->postJson(
            "/api/productos-proveedor/{$proveedorAjeno->Id_Proveedor}",
            $this->datosProducto(),
            $this->cabecerasComo($compras, $empresaPropia)
        )->assertNotFound();
    }

    public function test_un_usuario_proveedor_no_entra_por_las_rutas_del_comprador(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor, $proveedor] = $this->crearProveedorConUsuario($empresa);
        [, $otroProveedor] = $this->crearProveedorConUsuario($empresa);

        // Ni siquiera sobre sí mismo: para lo suyo tiene /mis-productos.
        $this->getJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            $this->cabecerasComo($usuarioProveedor, $empresa)
        )->assertForbidden();

        // Y mucho menos sobre el catálogo de un competidor.
        $this->getJson(
            "/api/productos-proveedor/{$otroProveedor->Id_Proveedor}",
            $this->cabecerasComo($usuarioProveedor, $empresa)
        )->assertForbidden();
    }

    public function test_calidad_no_gestiona_productos_de_proveedores(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->getJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            $this->cabecerasComo($calidad, $empresa)
        )->assertForbidden();
    }

    public function test_el_comprador_tampoco_puede_registrar_sin_los_documentos_obligatorios(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $producto = Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => 'SIN DOCUMENTOS',
            'Activo' => 1,
            'Bloqueado' => 0,
            'Fecha_Creacion' => now(),
        ]);

        // Decisión del usuario (10-sep-2026): el comprador pasa por el MISMO
        // circuito que el proveedor, sin atajos.
        $this->postJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}/registrar",
            ['ids' => [$producto->Id_Producto]],
            $this->cabecerasComo($compras, $empresa)
        )->assertStatus(422);

        $this->assertFalse((bool) $producto->fresh()->Bloqueado);
    }

    public function test_editar_un_producto_aprobado_lo_devuelve_a_calificacion(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        // El proveedor ya cerró una ronda de calificación de productos.
        $proveedor->forceFill(['Fecha_Registro_Calificacion_Productos' => now()])->save();

        $producto = Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => 'YA APROBADO',
            'Precio' => 5,
            'Activo' => 1,
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Aprobado',
            'Comentario_Calificacion' => 'Todo correcto',
            'Fecha_Calificacion' => now(),
            'Fecha_Creacion' => now(),
        ]);

        $this->putJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$producto->Id_Producto}",
            $this->datosProducto(['nombre_producto' => 'aprobado y corregido', 'precio' => 7.25]),
            $this->cabecerasComo($compras, $empresa)
        )->assertOk();

        $actualizado = $producto->fresh();

        // Los datos nuevos se guardaron, precio incluido.
        $this->assertSame('APROBADO Y CORREGIDO', $actualizado->Nombre_Producto);
        $this->assertSame(7.25, (float) $actualizado->Precio);

        // Pero el producto YA NO está aprobado: vuelve a la cola, y la
        // calificación anterior se limpia para que nadie lea el "Aprobado"
        // de ayer como si fuera el de estos datos.
        $this->assertSame('Pendiente', $actualizado->Estado_Calificacion);
        $this->assertTrue((bool) $actualizado->Bloqueado);
        $this->assertNull($actualizado->Comentario_Calificacion);
        $this->assertNull($actualizado->Fecha_Calificacion);

        // Y la ronda del proveedor se reabre, o este producto no aparecería
        // nunca en la bandeja de quien califica.
        $this->assertNull($proveedor->fresh()->Fecha_Registro_Calificacion_Productos);
    }

    public function test_un_producto_en_revision_no_se_puede_editar(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        // Bloqueado + Pendiente = alguien de Calidad lo está mirando AHORA.
        $producto = Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => 'EN REVISION',
            'Precio' => 5,
            'Activo' => 1,
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Pendiente',
            'Fecha_Creacion' => now(),
        ]);

        $this->putJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$producto->Id_Producto}",
            $this->datosProducto(['nombre_producto' => 'INTENTO DE CAMBIO', 'precio' => 999]),
            $this->cabecerasComo($compras, $empresa)
        )->assertForbidden();

        $this->assertSame('EN REVISION', $producto->fresh()->Nombre_Producto);
        $this->assertSame(5.0, (float) $producto->fresh()->Precio);
    }

    public function test_el_precio_congelado_por_una_solicitud_no_se_pisa_al_editar(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $producto = Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => 'CON SOLICITUD ABIERTA',
            'Precio' => 5,
            'Activo' => 1,
            'Bloqueado' => 0,
            'Precio_En_Revision' => 1,
            'Fecha_Creacion' => now(),
        ]);

        $this->putJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$producto->Id_Producto}",
            $this->datosProducto(['nombre_producto' => 'CON SOLICITUD ABIERTA', 'precio' => 999]),
            $this->cabecerasComo($compras, $empresa)
        )->assertStatus(422);

        $this->assertSame(5.0, (float) $producto->fresh()->Precio);
    }

    public function test_el_proveedor_tambien_puede_reeditar_su_producto_aprobado(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $producto = Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => 'APROBADO DEL PROVEEDOR',
            'Precio' => 3,
            'Activo' => 1,
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Aprobado',
            'Fecha_Creacion' => now(),
        ]);

        // Decisión del usuario (10-sep-2026): esto vale igual para las dos
        // pantallas, no es un permiso extra del personal interno.
        $this->putJson(
            "/api/mis-productos/{$producto->Id_Producto}",
            $this->datosProducto(['nombre_producto' => 'reeditado por el proveedor', 'precio' => 4]),
            $this->cabecerasComo($usuarioProveedor, $empresa)
        )->assertOk();

        $actualizado = $producto->fresh();

        $this->assertSame('REEDITADO POR EL PROVEEDOR', $actualizado->Nombre_Producto);
        $this->assertSame(4.0, (float) $actualizado->Precio);
        $this->assertSame('Pendiente', $actualizado->Estado_Calificacion);
        $this->assertTrue((bool) $actualizado->Bloqueado);
    }

    public function test_los_grupos_de_producto_se_guardan_se_cambian_y_se_pueden_quitar(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $grupos = GrupoProducto::where('Activo', 1)->orderBy('Orden')->take(3)->pluck('Id_Grupo_Producto');
        $this->assertGreaterThanOrEqual(3, $grupos->count(), 'La migración siembra EK, CD, PH e IM.');

        // 1. Se crea con dos grupos.
        $creado = $this->postJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            $this->datosProducto(['grupos' => [$grupos[0], $grupos[1]]]),
            $this->cabecerasComo($compras, $empresa)
        );
        $creado->assertCreated();

        $idProducto = $creado->json('id_producto');
        $producto = Producto::find($idProducto);

        $this->assertEqualsCanonicalizing(
            [$grupos[0], $grupos[1]],
            $producto->grupos()->pluck('Grupo_Producto.Id_Grupo_Producto')->all()
        );

        // 2. Se edita a un grupo distinto.
        $this->putJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$idProducto}",
            $this->datosProducto(['grupos' => [$grupos[2]]]),
            $this->cabecerasComo($compras, $empresa)
        )->assertOk();

        $this->assertSame(
            [$grupos[2]],
            $producto->fresh()->grupos()->pluck('Grupo_Producto.Id_Grupo_Producto')->all()
        );

        // 3. Un arreglo VACÍO quita todas las etiquetas (es distinto de no
        //    mandar el campo, ver ProductoService::actualizar).
        $this->putJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$idProducto}",
            $this->datosProducto(['grupos' => []]),
            $this->cabecerasComo($compras, $empresa)
        )->assertOk();

        $this->assertCount(0, $producto->fresh()->grupos);

        // 4. No mandar 'grupos' NO borra los que ya tenía.
        $this->putJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$idProducto}",
            $this->datosProducto(['grupos' => [$grupos[0]]]),
            $this->cabecerasComo($compras, $empresa)
        )->assertOk();

        $this->putJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$idProducto}",
            $this->datosProducto(),
            $this->cabecerasComo($compras, $empresa)
        )->assertOk();

        $this->assertSame(
            [$grupos[0]],
            $producto->fresh()->grupos()->pluck('Grupo_Producto.Id_Grupo_Producto')->all()
        );
    }

    public function test_el_volumen_se_calcula_de_las_medidas_de_la_unidad(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $respuesta = $this->postJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            $this->datosProducto([
                'unidad_largo_cm' => 10,
                'unidad_ancho_cm' => 5,
                'unidad_alto_cm' => 3,
                'masterpack_largo_cm' => 40,
                'masterpack_ancho_cm' => 30,
                'masterpack_alto_cm' => 25,
                'unidad_por_caja' => 12,
            ]),
            $this->cabecerasComo($compras, $empresa)
        );

        $respuesta->assertCreated();

        $producto = Producto::find($respuesta->json('id_producto'));

        // 10 x 5 x 3 = 150 cm³ = 0,000150 m³. Con los decimal(10,3) de
        // antes esto se guardaba como 0.000, que es justamente lo que la
        // migración 2026_09_12_090000 vino a arreglar.
        $this->assertSame(0.00015, (float) $producto->Volumen);

        $this->assertSame(40.0, (float) $producto->Masterpack_Largo_Cm);
        $this->assertSame(12, (int) $producto->Unidad_Por_Caja);

        // El masterpack tiene su PROPIO volumen: 40 x 30 x 25 = 30.000 cm³
        // = 0,03 m³. No es el de la unidad por las 12 que trae (0,0018),
        // porque entre medio hay separadores, relleno y el cartón.
        $this->assertSame(0.03, (float) $producto->Volumen_Masterpack);
        $this->assertNotSame(
            (float) $producto->Volumen_Masterpack,
            (float) $producto->Volumen * 12
        );
    }

    public function test_sin_las_tres_medidas_el_volumen_queda_vacio(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $respuesta = $this->postJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            // Falta el alto -> no hay volumen que calcular. Null y no 0:
            // 0 se leería como "no ocupa lugar" en vez de "no se sabe".
            $this->datosProducto(['unidad_largo_cm' => 10, 'unidad_ancho_cm' => 5]),
            $this->cabecerasComo($compras, $empresa)
        );

        $respuesta->assertCreated();

        $producto = Producto::find($respuesta->json('id_producto'));

        $this->assertNull($producto->Volumen);
        // Y el del masterpack tampoco, que ni siquiera se cargó.
        $this->assertNull($producto->Volumen_Masterpack);
    }

    public function test_el_paquete_exige_decir_cuanto_trae(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $idPaquete = (int) UnidadPresentacion::where('Nombre_Unidad', 'Paquete')->value('Id_Unidad_Presentacion');
        $this->assertGreaterThan(0, $idPaquete, 'El catálogo de unidades debe tener "Paquete".');

        $cabeceras = $this->cabecerasComo($compras, $empresa);

        // Unidad = Paquete y sin contenido -> se rechaza.
        $this->postJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            $this->datosProducto(['id_unidad_presentacion' => $idPaquete]),
            $cabeceras
        )->assertStatus(422)->assertJsonValidationErrors('contenido_paquete');

        // Con el contenido, pasa.
        $ok = $this->postJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            $this->datosProducto(['id_unidad_presentacion' => $idPaquete, 'contenido_paquete' => 6]),
            $cabeceras
        );
        $ok->assertCreated();
        $this->assertSame(6, (int) Producto::find($ok->json('id_producto'))->Contenido_Paquete);

        // Con cualquier OTRA unidad el campo no se exige.
        $this->postJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            $this->datosProducto(),
            $cabeceras
        )->assertCreated();
    }

    public function test_el_proveedor_sigue_viendo_y_editando_solo_lo_suyo(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor, $proveedor] = $this->crearProveedorConUsuario($empresa);
        [, $otroProveedor] = $this->crearProveedorConUsuario($empresa);

        $ajeno = Producto::create([
            'Id_Proveedor' => $otroProveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => 'DEL OTRO',
            'Activo' => 1,
            'Bloqueado' => 0,
            'Fecha_Creacion' => now(),
        ]);

        $cabeceras = $this->cabecerasComo($usuarioProveedor, $empresa);

        // Su propio alta sigue funcionando igual que antes del refactor.
        $propio = $this->postJson('/api/mis-productos', $this->datosProducto(), $cabeceras);
        $propio->assertCreated();
        $this->assertEquals(
            $proveedor->Id_Proveedor,
            Producto::find($propio->json('id_producto'))->Id_Proveedor
        );

        // Y el producto de otro proveedor no existe para él, ni para editarlo.
        $this->putJson("/api/mis-productos/{$ajeno->Id_Producto}", $this->datosProducto(), $cabeceras)
            ->assertNotFound();

        $this->assertSame('DEL OTRO', $ajeno->fresh()->Nombre_Producto);
    }
}
