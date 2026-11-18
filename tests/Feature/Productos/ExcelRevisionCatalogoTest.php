<?php

namespace Tests\Feature\Productos;

use App\Models\Empresa;
use App\Modules\Ficha_Productos\Models\GrupoProducto;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Proveedores\Models\Proveedor;
use Tests\TestCase;

/**
 * Excel de los productos que esperan a Catálogo en "Productos por revisar"
 * (18-nov-2026, pedido del usuario): Catálogo descarga todo lo pendiente
 * con el proveedor y los datos del producto, lo corrige en el archivo y lo
 * vuelve a subir.
 *
 * Lo que se decidió con el usuario y acá queda fijado:
 *  - se editan los mismos campos que el formulario del producto; ID,
 *    proveedor, RUC y código BC son de referencia;
 *  - lo usan Catálogo, Admin y Sistemas;
 *  - solo actualiza datos: el producto sigue esperando a Catálogo.
 */
class ExcelRevisionCatalogoTest extends TestCase
{
    private const EXPORTAR = '/api/productos-revision/catalogo/exportar';
    private const VALIDAR = '/api/productos-revision/catalogo/importar/validar';
    private const IMPORTAR = '/api/productos-revision/catalogo/importar';

    private function idUnidad(string $nombre = 'Unidad'): int
    {
        $id = UnidadPresentacion::where('Nombre_Unidad', $nombre)->where('Activo', 1)->value('Id_Unidad_Presentacion');

        if ($id === null) {
            $this->markTestSkipped("No existe la unidad de presentación \"{$nombre}\" en la base.");
        }

        return (int) $id;
    }

    /** Producto enviado por el proveedor y esperando el primer paso. */
    private function pendiente(Proveedor $proveedor, ?string $codigoBc, array $extra = []): Producto
    {
        return Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => 'PRODUCTO DE PRUEBA',
            'Clasificacion' => Producto::PERECIBLES,
            'Codigo_Barras' => '7861234567890',
            'Precio' => 5,
            'Bc_Nro_Producto' => $codigoBc,
            'Activo' => 1,
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Pendiente',
            'Etapa_Aprobacion' => Producto::ETAPA_COMPRAS,
            'Fecha_Creacion' => now(),
            ...$extra,
        ]);
    }

    /** @return array{0: Empresa, 1: Proveedor, 2: Producto, 3: array<string, string>} */
    private function escenario(string $rol = 'Catálogo'): array
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        return [
            $empresa,
            $proveedor,
            $this->pendiente($proveedor, 'PRD-EXCEL-1'),
            $this->cabecerasComo($this->crearUsuarioInterno($empresa, $rol), $empresa),
        ];
    }

    /** La fila tal como la manda el navegador: todo texto. */
    private function fila(Producto $producto, array $celdas, int $nro = 2): array
    {
        return ['fila' => $nro, 'id_producto' => (int) $producto->Id_Producto, ...$celdas];
    }

    public function test_catalogo_descarga_lo_que_le_espera_con_proveedor_y_datos(): void
    {
        [, $proveedor, $conCodigo, $cabeceras] = $this->escenario();
        $sinCodigo = $this->pendiente($proveedor, null, ['Nombre_Producto' => 'NUEVO SIN CODIGO']);
        $enCalidad = $this->pendiente($proveedor, 'PRD-EXCEL-2', ['Etapa_Aprobacion' => Producto::ETAPA_CALIDAD]);

        $respuesta = $this->getJson(self::EXPORTAR, $cabeceras)->assertOk();

        $filas = collect($respuesta->json('filas'))->keyBy('id_producto');

        // Solo lo que dice "Esperando a Catálogo": ni los nuevos (son de
        // Compras) ni los que ya pasaron a Calidad.
        $this->assertTrue($filas->has($conCodigo->Id_Producto));
        $this->assertFalse($filas->has($sinCodigo->Id_Producto));
        $this->assertFalse($filas->has($enCalidad->Id_Producto));

        $fila = $filas->get($conCodigo->Id_Producto);
        $this->assertSame($proveedor->nombreVisible(), $fila['proveedor']);
        $this->assertSame((string) $proveedor->Ruc, $fila['ruc']);
        $this->assertSame('PRD-EXCEL-1', $fila['bc_nro_producto']);
        $this->assertSame('PRODUCTO DE PRUEBA', $fila['nombre_producto']);
        $this->assertSame('7861234567890', $fila['codigo_barras']);
        $this->assertSame('Perecible', $fila['categoria']);
        $this->assertSame('Unidad', $fila['unidad']);
        $this->assertSame('5.00', $fila['precio']);

        // La hoja de ayuda del archivo sale de acá.
        $this->assertSame(['Perecible', 'No perecible', 'Fruver'], $respuesta->json('valores_validos.categorias'));
        $this->assertContains('Unidad', $respuesta->json('valores_validos.unidades'));
    }

    public function test_solo_catalogo_admin_y_sistemas_usan_el_excel(): void
    {
        [$empresa, , $producto] = $this->escenario();
        $filas = ['filas' => [$this->fila($producto, ['precio' => '9'])]];

        foreach (['Admin', 'Sistemas'] as $rol) {
            $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, $rol), $empresa);
            $this->getJson(self::EXPORTAR, $cabeceras)->assertOk();
            $this->postJson(self::VALIDAR, $filas, $cabeceras)->assertOk();
        }

        // Compras y Calidad entran a la bandeja, pero el Excel no es de ellos.
        foreach (['Compras', 'Calidad', 'Guardia'] as $rol) {
            $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, $rol), $empresa);
            $this->getJson(self::EXPORTAR, $cabeceras)->assertForbidden();
            $this->postJson(self::VALIDAR, $filas, $cabeceras)->assertForbidden();
            $this->postJson(self::IMPORTAR, $filas, $cabeceras)->assertForbidden();
        }

        [$usuarioProveedor] = $this->crearProveedorConUsuario($empresa);
        $this->postJson(self::IMPORTAR, $filas, $this->cabecerasComo($usuarioProveedor, $empresa))->assertForbidden();

        $this->assertSame(5.0, (float) $producto->fresh()->Precio);
    }

    public function test_subir_el_archivo_actualiza_los_datos_y_el_producto_sigue_esperando_a_catalogo(): void
    {
        [, , $producto, $cabeceras] = $this->escenario();
        $this->idUnidad('Kilogramo');
        $grupo = GrupoProducto::where('Activo', 1)->orderBy('Id_Grupo_Producto')->first();

        $filas = ['filas' => [$this->fila($producto, [
            'nombre_producto' => 'nombre corregido',
            'codigo_barras' => '7860000000017',
            'categoria' => 'No perecible',
            'unidad' => 'kilogramo',
            // Coma decimal y 4 decimales: así lo escribe un Excel en español.
            'precio' => '1,2575',
            'peso' => '0.5',
            'unidad_por_caja' => '12',
            'unidad_largo_cm' => '10',
            'unidad_ancho_cm' => '20',
            'unidad_alto_cm' => '30',
            'grupos' => $grupo ? $grupo->Codigo : '',
        ])]];

        // Paso 1: el reporte dice qué va a cambiar y NO guarda nada.
        $previo = $this->postJson(self::VALIDAR, $filas, $cabeceras)->assertOk();
        $previo->assertJson(['simulacion' => true, 'actualizados' => 1, 'sin_cambio' => 0, 'con_error' => 0]);

        $cambios = collect($previo->json('cambios.0.cambios'))->keyBy('campo');
        $this->assertSame(['campo' => 'Precio', 'antes' => '5.00', 'despues' => '1.2575'], $cambios->get('Precio'));
        $this->assertSame('No perecible', $cambios->get('Categoría')['despues']);
        $this->assertSame('PRODUCTO DE PRUEBA', $producto->fresh()->Nombre_Producto);

        // Paso 2: ahora sí.
        $this->postJson(self::IMPORTAR, $filas, $cabeceras)
            ->assertOk()
            ->assertJson(['simulacion' => false, 'actualizados' => 1, 'con_error' => 0]);

        $guardado = $producto->fresh();
        $this->assertSame('NOMBRE CORREGIDO', $guardado->Nombre_Producto);
        $this->assertSame('7860000000017', $guardado->Codigo_Barras);
        $this->assertSame(Producto::NO_PERECIBLES, $guardado->Clasificacion);
        $this->assertEquals($this->idUnidad('Kilogramo'), $guardado->Id_Unidad_Presentacion);
        $this->assertSame(1.2575, (float) $guardado->Precio);
        $this->assertSame(0.5, (float) $guardado->Peso);
        $this->assertEquals(12, $guardado->Unidad_Por_Caja);
        // El volumen se sigue calculando de las medidas, como en el formulario.
        $this->assertSame(0.006, (float) $guardado->Volumen);

        if ($grupo) {
            $this->assertEquals([$grupo->Id_Grupo_Producto], $guardado->grupos->pluck('Id_Grupo_Producto')->all());
        }

        // Solo datos: sigue en la bandeja de Catálogo, sin aprobar ni devolver.
        $this->assertSame('Pendiente', $guardado->Estado_Calificacion);
        $this->assertSame(Producto::ETAPA_COMPRAS, $guardado->Etapa_Aprobacion);
        $this->assertTrue((bool) $guardado->Bloqueado);
        // El código BC es de referencia: no se toca.
        $this->assertSame('PRD-EXCEL-1', trim((string) $guardado->Bc_Nro_Producto));
    }

    public function test_volver_a_subir_el_archivo_sin_tocarlo_no_cambia_nada(): void
    {
        [, , $producto, $cabeceras] = $this->escenario();

        $descargada = collect($this->getJson(self::EXPORTAR, $cabeceras)->json('filas'))
            ->firstWhere('id_producto', (int) $producto->Id_Producto);

        // Tal cual salió: mismas claves, mismos textos.
        $celdas = collect($descargada)->except(['id_producto', 'proveedor', 'ruc', 'bc_nro_producto'])->all();

        $this->postJson(self::IMPORTAR, ['filas' => [$this->fila($producto, $celdas)]], $cabeceras)
            ->assertOk()
            ->assertJson(['actualizados' => 0, 'sin_cambio' => 1, 'con_error' => 0]);

        $this->assertNull($producto->fresh()->Fecha_Modificacion);
    }

    public function test_la_columna_que_no_viene_no_se_toca_y_la_celda_vacia_borra_el_dato(): void
    {
        [, , $producto, $cabeceras] = $this->escenario();

        // Solo vino la columna PRECIO: el código de barras queda como estaba.
        $this->postJson(self::IMPORTAR, ['filas' => [$this->fila($producto, ['precio' => '7.5'])]], $cabeceras)
            ->assertOk()->assertJson(['actualizados' => 1]);

        $this->assertSame(7.5, (float) $producto->fresh()->Precio);
        $this->assertSame('7861234567890', $producto->fresh()->Codigo_Barras);

        // Vino la columna y la celda está vacía: el dato opcional se borra.
        $this->postJson(self::IMPORTAR, ['filas' => [$this->fila($producto, ['codigo_barras' => ''])]], $cabeceras)
            ->assertOk()->assertJson(['actualizados' => 1]);

        $this->assertNull($producto->fresh()->Codigo_Barras);
        $this->assertSame(7.5, (float) $producto->fresh()->Precio);
    }

    public function test_las_filas_con_error_se_descartan_y_las_validas_se_guardan(): void
    {
        [, $proveedor, $bueno, $cabeceras] = $this->escenario();
        $precioMalo = $this->pendiente($proveedor, 'PRD-EXCEL-3');
        $muchosDecimales = $this->pendiente($proveedor, 'PRD-EXCEL-4');
        $categoriaMala = $this->pendiente($proveedor, 'PRD-EXCEL-5');
        $unidadMala = $this->pendiente($proveedor, 'PRD-EXCEL-6');
        $sinNombre = $this->pendiente($proveedor, 'PRD-EXCEL-7');
        $paqueteSinContenido = $this->pendiente($proveedor, 'PRD-EXCEL-8');
        $this->idUnidad('Paquete');

        $respuesta = $this->postJson(self::IMPORTAR, ['filas' => [
            $this->fila($bueno, ['precio' => '2.5'], 2),
            $this->fila($precioMalo, ['precio' => 'abc'], 3),
            $this->fila($muchosDecimales, ['precio' => '1.23456'], 4),
            $this->fila($categoriaMala, ['categoria' => 'Congelados'], 5),
            $this->fila($unidadMala, ['unidad' => 'Tonelada métrica'], 6),
            $this->fila($sinNombre, ['nombre_producto' => '   '], 7),
            $this->fila($paqueteSinContenido, ['unidad' => 'Paquete'], 8),
            // Repetido en el archivo.
            $this->fila($bueno, ['precio' => '99'], 9),
        ]], $cabeceras)->assertOk();

        $respuesta->assertJson(['total_filas' => 8, 'actualizados' => 1, 'con_error' => 7]);

        $errores = collect($respuesta->json('errores'))->keyBy('fila');
        $this->assertStringContainsString('no es un número válido', $errores->get(3)['motivo']);
        $this->assertStringContainsString('hasta 4 decimales', $errores->get(4)['motivo']);
        $this->assertStringContainsString('Congelados', $errores->get(5)['motivo']);
        $this->assertStringContainsString('Tonelada métrica', $errores->get(6)['motivo']);
        $this->assertStringContainsString('no puede quedar vacío', $errores->get(7)['motivo']);
        $this->assertStringContainsString('Contenido x paquete', $errores->get(8)['motivo']);
        $this->assertStringContainsString('repetido', $errores->get(9)['motivo']);

        // Importación parcial: la válida entró, las demás quedaron intactas.
        $this->assertSame(2.5, (float) $bueno->fresh()->Precio);
        $this->assertSame(5.0, (float) $precioMalo->fresh()->Precio);
        $this->assertSame(Producto::PERECIBLES, $categoriaMala->fresh()->Clasificacion);
        $this->assertSame('PRODUCTO DE PRUEBA', $sinNombre->fresh()->Nombre_Producto);
    }

    public function test_no_actualiza_lo_que_no_espera_a_catalogo_ni_lo_de_otra_empresa(): void
    {
        [, $proveedor, , $cabeceras] = $this->escenario();
        $deCompras = $this->pendiente($proveedor, null);
        $enCalidad = $this->pendiente($proveedor, 'PRD-EXCEL-9', ['Etapa_Aprobacion' => Producto::ETAPA_CALIDAD]);

        [, $proveedorAjeno] = $this->crearProveedorConUsuario($this->crearEmpresa());
        $ajeno = $this->pendiente($proveedorAjeno, 'PRD-EXCEL-10');

        $respuesta = $this->postJson(self::IMPORTAR, ['filas' => [
            $this->fila($deCompras, ['precio' => '9'], 2),
            $this->fila($enCalidad, ['precio' => '9'], 3),
            $this->fila($ajeno, ['precio' => '9'], 4),
        ]], $cabeceras)->assertOk();

        $respuesta->assertJson(['actualizados' => 0, 'con_error' => 3]);

        foreach ([$deCompras, $enCalidad, $ajeno] as $producto) {
            $this->assertSame(5.0, (float) $producto->fresh()->Precio);
        }
    }

    public function test_el_precio_congelado_por_una_solicitud_no_se_pisa_desde_el_excel(): void
    {
        [, $proveedor, , $cabeceras] = $this->escenario();
        $congelado = $this->pendiente($proveedor, 'PRD-EXCEL-11', ['Precio_En_Revision' => 1]);

        $respuesta = $this->postJson(self::IMPORTAR, ['filas' => [
            $this->fila($congelado, ['precio' => '9', 'codigo_barras' => '111']),
        ]], $cabeceras)->assertOk();

        $respuesta->assertJson(['actualizados' => 0, 'con_error' => 1]);
        $this->assertStringContainsString('cambio de precio pendiente', $respuesta->json('errores.0.motivo'));
        $this->assertSame(5.0, (float) $congelado->fresh()->Precio);

        // Sin tocar el precio, el resto del producto sí se corrige.
        $this->postJson(self::IMPORTAR, ['filas' => [
            $this->fila($congelado, ['precio' => '5.00', 'codigo_barras' => '111']),
        ]], $cabeceras)->assertOk()->assertJson(['actualizados' => 1, 'con_error' => 0]);

        $this->assertSame('111', $congelado->fresh()->Codigo_Barras);
    }
}
