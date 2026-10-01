<?php

namespace Tests\Feature\Flujos;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Documentos_Proveedor\Services\DocumentoProveedorService;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\TipoDocumentoProducto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Pedidos\Models\DetallePedidoCompra;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Pedidos\Services\SincronizacionPedidosService;
use App\Modules\Proveedores\Models\Banco;
use App\Modules\Proveedores\Models\CategoriaProducto;
use App\Modules\Proveedores\Models\ClaseProveedor;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\GrupoImpuestoBC;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\CalificacionGlobalService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Recorrido COMPLETO del alta de un proveedor, de punta a punta y por los
 * endpoints reales, en los dos sabores que existen:
 *
 *   A) proveedor de PRODUCTOS   -> ficha + documentos + catálogo aprobado
 *   B) proveedor de SERVICIOS   -> ficha + documentos, sin catálogo
 *
 * El caso B es el que más riesgo tiene: la condición "tiene al menos un
 * producto aprobado" lo dejaría trabado en Aspirante para siempre si no
 * estuviera exceptuado (ver CalificacionProveedorService::esSoloServicios).
 *
 * No se simula nada por abajo: se postea a /api/mi-ficha, se suben PDFs a
 * /api/mi-documentos, y se califica por /api/proveedores/... igual que lo
 * hace el portal. Lo único falso son el disco (para no escribir en el
 * repositorio real) y el correo.
 */
class AltaProveedorCompletaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sin esto los PDF de prueba se escribirían en /var/repositorio.
        Storage::fake('repositorio_proveedores');
        Mail::fake();
    }

    // ---------------------------------------------------------------
    // Pasos del proveedor
    // ---------------------------------------------------------------

    /** Secciones 1, 2 y 3 de la ficha, por los endpoints reales. */
    private function completarFicha(Usuario $usuario, Empresa $empresa, Proveedor $proveedor, string $nombreClase): void
    {
        $cabeceras = $this->cabecerasComo($usuario, $empresa);

        $this->putJson('/api/mi-ficha/seccion-1', [
            'ruc' => $proveedor->Ruc,
            'razon_social' => $proveedor->Razon_Social,
            // Código real del catálogo Grupo_Impuesto_BC: es el valor que
            // se postea a BC, no texto libre.
            'clase_contribuyente' => GrupoImpuestoBC::where('Activo', 1)->value('Codigo'),
            'nombre_comercial' => 'Comercial Test',
            'email' => $proveedor->Email,
            'telefono' => '042345678',
            'direccion' => 'Av. Siempre Viva 123',
            // Fuera de Quito a propósito: así el checklist pide Bomberos y
            // no LUAE, que es el caso de la mayoría de proveedores.
            'ciudad' => 'Guayaquil',
            'latitud' => -2.17,
            'longitud' => -79.92,
            'representante_legal' => 'Ana Torres',
            'correo_representante' => 'ana@test.local',
            'telefono_representante' => '0991111111',
            'contacto_venta' => 'Luis Vega',
            'correo_venta' => 'ventas@test.local',
            'telefono_contacto_venta' => '0992222222',
            'contacto_calidad' => 'Sara Ruiz',
            'correo_calidad' => 'calidad@test.local',
            'telefono_contacto_calidad' => '0993333333',
            'contacto_contabilidad' => 'Jose Paz',
            'correo_contabilidad' => 'conta@test.local',
            'telefono_contabilidad' => '0994444444',
            'acepta_politicas' => true,
        ], $cabeceras)->assertOk();

        $idClase = ClaseProveedor::where('Nombre_Clase', $nombreClase)->value('Id_Clase_Proveedor');
        $this->assertNotNull($idClase, "Falta la clase de proveedor '{$nombreClase}' en la base de pruebas.");

        $this->putJson('/api/mi-ficha/seccion-2', [
            'id_clases' => [$idClase],
            'acepta_politicas' => true,
        ], $cabeceras)->assertOk();

        $this->putJson('/api/mi-ficha/seccion-3', [
            'id_categorias' => [CategoriaProducto::where('Activo', 1)->value('Id_Categoria_Producto')],
            'acepta_politicas' => true,
        ], $cabeceras)->assertOk();
    }

    /**
     * Sube un PDF por cada tipo OBLIGATORIO que le corresponde, declara la
     * cuenta bancaria y registra la documentación.
     *
     * @return int cantidad de documentos subidos
     */
    private function cargarYRegistrarDocumentacion(Usuario $usuario, Empresa $empresa, Proveedor $proveedor): int
    {
        $cabeceras = $this->cabecerasComo($usuario, $empresa);

        $tipos = app(DocumentoProveedorService::class)
            ->tiposObligatoriosAplicables($proveedor->fresh());

        foreach ($tipos as $tipo) {
            $datos = ['archivo' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf')];

            if ($tipo->Requiere_Fecha_Caducidad) {
                // Un año de vigencia: cómodamente por encima del mínimo de
                // un mes que exige SubirDocumentoRequest.
                $datos['fecha_caducidad'] = now()->addYear()->toDateString();
            }

            // 201: el endpoint de carga devuelve "creado", no "ok".
            $this->post("/api/mi-documentos/{$tipo->Id_Tipo_Documento}", $datos, $cabeceras)
                ->assertSuccessful();
        }

        $this->putJson('/api/mi-ficha/cuenta-bancaria', [
            'id_banco' => Banco::where('Activo', 1)->value('Id_Banco'),
            'tipo_cuenta' => 'AHO',
            'nro_cuenta' => '1234567890',
        ], $cabeceras)->assertOk();

        $this->postJson('/api/mi-documentos/registrar', [], $cabeceras)->assertOk();

        $this->assertNotNull(
            $proveedor->fresh()->Fecha_Registro_Documentacion,
            'La documentación tenía que quedar registrada.'
        );

        return $tipos->count();
    }

    /** Crea un producto con todos sus documentos obligatorios y lo envía a aprobar. */
    private function crearYEnviarProducto(Usuario $usuario, Empresa $empresa): int
    {
        $cabeceras = $this->cabecerasComo($usuario, $empresa);

        // Kilogramo y no Paquete: Paquete obligaría a contenido_paquete.
        $unidad = UnidadPresentacion::where('Nombre_Unidad', 'Kilogramo')->first()
            ?? UnidadPresentacion::firstOrFail();

        $idProducto = (int) $this->postJson('/api/mis-productos', [
            'nombre_producto' => 'PRODUCTO DE PRUEBA E2E',
            'id_unidad_presentacion' => $unidad->Id_Unidad_Presentacion,
            'precio' => 10.5,
            'unidad_por_caja' => 12,
        ], $cabeceras)->assertSuccessful()->json('id_producto');

        $this->assertNotNull($idProducto, 'No se pudo crear el producto.');

        foreach (TipoDocumentoProducto::where('Activo', 1)->where('Obligatorio', 1)->get() as $tipo) {
            $this->post(
                "/api/mis-productos/{$idProducto}/documentos/{$tipo->Id_Tipo_Documento_Producto}",
                [
                    'archivo' => UploadedFile::fake()->create('ficha.pdf', 50, 'application/pdf'),
                    // Hay tipos de documento de producto que la exigen; para
                    // los que no, sobra sin molestar.
                    'fecha_caducidad' => now()->addYear()->toDateString(),
                ],
                $cabeceras
            )->assertSuccessful();
        }

        $this->postJson('/api/mis-productos/registrar', ['ids' => [$idProducto]], $cabeceras)->assertOk();

        $producto = Producto::findOrFail($idProducto);
        $this->assertEquals(1, $producto->Bloqueado, 'Enviado a aprobar, el producto queda bloqueado.');
        $this->assertSame(
            Producto::ETAPA_COMPRAS,
            $producto->Etapa_Aprobacion,
            'El producto recién enviado tiene que esperar a COMPRAS, no a Calidad.'
        );

        return $idProducto;
    }

    // ---------------------------------------------------------------
    // Pasos del personal interno
    // ---------------------------------------------------------------

    private function aprobarFichaYDocumentos(Usuario $interno, Empresa $empresa, Proveedor $proveedor): void
    {
        $cabeceras = $this->cabecerasComo($interno, $empresa);

        $this->postJson(
            "/api/proveedores/{$proveedor->Id_Proveedor}/ficha-calificacion",
            // Sin 'campos_rechazados': un array vacío falla el min:1.
            ['aprobado' => true],
            $cabeceras
        )->assertOk();

        $documentos = $this->getJson(
            "/api/proveedores/{$proveedor->Id_Proveedor}/documentos-calificacion",
            $cabeceras
        )->assertOk()->json();

        $ids = collect($documentos['documentos'] ?? [])
            ->flatMap(fn ($tipo) => $tipo['documentos'] ?? [])
            ->pluck('id_documento_proveedor')
            ->filter();

        $this->assertNotEmpty($ids, 'El revisor tiene que ver los documentos cargados.');

        foreach ($ids as $idDocumento) {
            $this->postJson(
                "/api/proveedores/documentos-calificacion/{$idDocumento}",
                ['aprobado' => true],
                $cabeceras
            )->assertOk();
        }

        $this->postJson(
            "/api/proveedores/{$proveedor->Id_Proveedor}/documentos-calificacion/registrar",
            [],
            $cabeceras
        )->assertOk();
    }

    // ---------------------------------------------------------------
    // A) Proveedor de PRODUCTOS
    // ---------------------------------------------------------------

    public function test_alta_completa_de_un_proveedor_de_productos(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Guayaquil']);
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        // --- 1. Ficha ---
        $this->completarFicha($usuario, $empresa, $proveedor, 'Comercializador');

        $ficha = $this->getJson('/api/mi-ficha', $this->cabecerasComo($usuario, $empresa))->assertOk()->json();
        $this->assertEquals(100, (int) $ficha['porcentaje_completado'], 'La ficha tiene que llegar al 100%.');

        // --- 2. Documentación ---
        $this->cargarYRegistrarDocumentacion($usuario, $empresa, $proveedor);

        // --- 3. Catálogo de productos ---
        $idProducto = $this->crearYEnviarProducto($usuario, $empresa);

        // --- 4. Compras deja pasar el producto a Calidad ---
        $this->postJson(
            "/api/productos-revision/{$idProducto}/aprobar",
            [],
            $this->cabecerasComo($compras, $empresa)
        )->assertOk();

        $this->assertSame(Producto::ETAPA_CALIDAD, Producto::find($idProducto)->Etapa_Aprobacion);

        // --- 5. Calificación de ficha y documentos ---
        $this->aprobarFichaYDocumentos($sistemas, $empresa, $proveedor);

        // Todavía NO puede estar aprobado: falta el producto.
        $this->assertEquals(
            EstadoProveedor::ASPIRANTE,
            $proveedor->fresh()->Id_Estado_Proveedor,
            'Un proveedor de productos no se aprueba hasta tener un producto aprobado.'
        );

        // --- 6. Calificación del producto y cierre ---
        $cabecerasSistemas = $this->cabecerasComo($sistemas, $empresa);

        $this->postJson(
            "/api/proveedores/productos-calificacion/{$idProducto}",
            ['aprobado' => true],
            $cabecerasSistemas
        )->assertOk();

        $this->postJson(
            "/api/proveedores/{$proveedor->Id_Proveedor}/productos-calificacion/registrar",
            [],
            $cabecerasSistemas
        )->assertOk();

        // --- 7. Veredicto ---
        $proveedor->refresh();
        $this->assertEquals(
            EstadoProveedor::APROBADO,
            $proveedor->Id_Estado_Proveedor,
            'Con ficha, documentos y producto aprobados el proveedor tiene que quedar APROBADO.'
        );
        $this->assertNotNull($proveedor->Fecha_Aprobacion);
    }

    // ---------------------------------------------------------------
    // B) Proveedor de SERVICIOS (sin productos)
    // ---------------------------------------------------------------

    public function test_alta_completa_de_un_proveedor_de_servicios_sin_productos(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Guayaquil']);
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $this->completarFicha($usuario, $empresa, $proveedor, ClaseProveedor::SERVICIO);
        $this->cargarYRegistrarDocumentacion($usuario, $empresa, $proveedor);

        // Ni un solo producto en todo el recorrido.
        $this->assertEquals(
            0,
            Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)->count(),
            'El de servicios no carga catálogo.'
        );

        $this->aprobarFichaYDocumentos($sistemas, $empresa, $proveedor);

        // ACÁ está el riesgo: sin la excepción de "solo servicios" este
        // proveedor quedaría Aspirante para siempre, porque nunca va a
        // tener un producto aprobado que lo destrabe.
        $this->assertEquals(
            EstadoProveedor::APROBADO,
            $proveedor->fresh()->Id_Estado_Proveedor,
            'Un proveedor de servicios se aprueba con ficha + documentación, sin productos.'
        );
    }

    // ---------------------------------------------------------------
    // ARCSA opcional (01-oct-2026)
    // ---------------------------------------------------------------

    public function test_el_permiso_arcsa_ya_no_frena_el_registro_de_la_documentacion(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Guayaquil']);

        $arcsa = TipoDocumento::where('Codigo_Archivo', 'ARCSA')->firstOrFail();
        $this->assertFalse((bool) $arcsa->Obligatorio, 'ARCSA tiene que estar marcado como opcional.');

        $this->completarFicha($usuario, $empresa, $proveedor, 'Comercializador');
        $this->cargarYRegistrarDocumentacion($usuario, $empresa, $proveedor);

        // Lo que importa: se registró la documentación SIN haber subido ARCSA.
        $this->assertSame(
            0,
            DB::table('Documento_Proveedor')
                ->where('Id_Proveedor', $proveedor->Id_Proveedor)
                ->where('Id_Tipo_Documento', $arcsa->Id_Tipo_Documento)
                ->count(),
            'El escenario solo vale si de verdad no se subió el ARCSA.'
        );
        $this->assertNotNull($proveedor->fresh()->Fecha_Registro_Documentacion);
    }

    public function test_el_arcsa_sigue_apareciendo_en_el_checklist_como_opcional(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Guayaquil']);

        $tipos = collect(
            $this->getJson('/api/mi-documentos', $this->cabecerasComo($usuario, $empresa))
                ->assertOk()
                ->json('documentos')
        );

        $arcsa = $tipos->first(fn ($tipo) => str_contains($tipo['nombre_documento'], 'ARCSA'));

        $this->assertNotNull($arcsa, 'Opcional no es invisible: el proveedor tiene que poder subirlo igual.');
        $this->assertFalse($arcsa['obligatorio']);
    }

    // ---------------------------------------------------------------
    // Pedidos desde BC y fill rate
    // ---------------------------------------------------------------

    public function test_los_pedidos_bajan_de_bc_y_alimentan_el_fill_rate(): void
    {
        $empresa = $this->crearEmpresa(['Empresa_BC' => 'E2E'.random_int(100, 999)]);
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $empresaBc = $empresa->Empresa_BC;
        $nroProveedor = 'PRV'.random_int(10000, 99999);
        $nroPedido = 'PED'.random_int(100000, 999999);
        $ahora = now()->format('Y-m-d\TH:i:s');

        DB::table('BC_Ficha_Proveedor')->insert([
            'Empresa' => $empresaBc,
            'Fecha_Registro' => $ahora,
            'Nro_Proveedor' => $nroProveedor,
            'Nombre' => $proveedor->Razon_Social,
            // Este es el enganche: BC_Ficha_Proveedor.Nro_Identificacion
            // contra Proveedor.Ruc de la misma empresa.
            'Nro_Identificacion' => $proveedor->Ruc,
        ]);

        DB::table('BC_Cab_Pedido_Compra')->insert([
            'Empresa' => $empresaBc,
            'Fecha_Registro' => $ahora,
            'Tipo_Documento' => 'Order',
            'Nro_Pedido' => $nroPedido,
            'Nro_Proveedor' => $nroProveedor,
            'Nombre_Proveedor' => $proveedor->Razon_Social,
            'Fecha_Recepcion_Esperada' => now()->addDays(2)->format('Y-m-d\TH:i:s'),
            // Dentro de la ventana móvil de 3 días que mira el servicio.
            'Fecha_Registro_BC' => $ahora,
            'Estado_Pedido' => 'Released',
        ]);

        foreach ([[1, 'ART-1', 100], [2, 'ART-2', 100]] as [$linea, $codigo, $cantidad]) {
            DB::table('BC_Det_Pedido_Compra')->insert([
                'Empresa' => $empresaBc,
                'Fecha_Registro' => $ahora,
                'Tipo_Documento' => 'Order',
                'Nro_Pedido' => $nroPedido,
                'Nro_Linea' => $linea,
                'Nro_Producto' => $codigo,
                'Descripcion' => 'Artículo de prueba',
                'Cantidad' => $cantidad,
            ]);
        }

        $sincronizados = app(SincronizacionPedidosService::class)
            ->sincronizar($empresa->Id_Empresa, $proveedor->Ruc);

        $this->assertSame(1, $sincronizados, 'El pedido de BC tenía que bajar al portal.');

        $pedido = PedidoCompra::where('Id_Empresa', $empresa->Id_Empresa)
            ->where('Nro_Pedido', $nroPedido)
            ->firstOrFail();

        $this->assertEquals($proveedor->Id_Proveedor, $pedido->Id_Proveedor, 'Se enganchó por RUC.');
        $this->assertSame('Abierto', $pedido->Estado);
        $this->assertSame(2, $pedido->lineas()->count());

        // Recepción parcial: 100 de 100 en una línea, 50 de 100 en la otra.
        $detalles = $pedido->lineas()->orderBy('Nro_Linea')->get();
        $detalles[0]->forceFill(['Cantidad_Recibida' => 100])->save();
        $detalles[1]->forceFill(['Cantidad_Recibida' => 50])->save();
        $pedido->forceFill(['Estado' => 'Cerrado'])->save();

        $fillRate = app(CalificacionGlobalService::class)
            ->fillRatePorPedido($proveedor->Id_Proveedor, $empresa->Id_Empresa);

        $this->assertArrayHasKey($pedido->Id_Pedido_Compra, $fillRate);
        $this->assertEqualsWithDelta(75.0, $fillRate[$pedido->Id_Pedido_Compra], 0.01, '150 de 200 = 75%.');
    }

    /**
     * Una sobre-entrega no puede tapar el faltante de otra línea: el tope
     * por línea es lo que evita que un pedido incumplido parezca cumplido.
     */
    public function test_una_sobreentrega_no_compensa_la_linea_faltante(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $pedido = PedidoCompra::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nro_Pedido' => 'TOPE'.random_int(10000, 99999),
            'Fecha_Registro_BC' => now(),
            'Estado_Pedido_BC' => 'Released',
            'Estado' => 'Cerrado',
            'Fecha_Sincronizacion' => now(),
            'Activo' => 1,
        ]);

        DetallePedidoCompra::create([
            'Id_Pedido_Compra' => $pedido->Id_Pedido_Compra,
            'Nro_Linea' => 1, 'Codigo_Producto' => 'A', 'Descripcion' => 'A',
            'Cantidad' => 100, 'Cantidad_Recibida' => 200,
        ]);
        DetallePedidoCompra::create([
            'Id_Pedido_Compra' => $pedido->Id_Pedido_Compra,
            'Nro_Linea' => 2, 'Codigo_Producto' => 'B', 'Descripcion' => 'B',
            'Cantidad' => 100, 'Cantidad_Recibida' => 0,
        ]);

        $fillRate = app(CalificacionGlobalService::class)
            ->fillRatePorPedido($proveedor->Id_Proveedor, $empresa->Id_Empresa);

        $this->assertEqualsWithDelta(
            50.0,
            $fillRate[$pedido->Id_Pedido_Compra],
            0.01,
            'Sin el tope por línea daría 100% sobre un pedido que entregó la mitad.'
        );
    }

    // ---------------------------------------------------------------
    // Calificación global
    // ---------------------------------------------------------------

    public function test_la_calificacion_global_no_castiga_al_proveedor_sin_movimiento(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Guayaquil']);
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $this->completarFicha($usuario, $empresa, $proveedor, ClaseProveedor::SERVICIO);
        $this->cargarYRegistrarDocumentacion($usuario, $empresa, $proveedor);
        $this->aprobarFichaYDocumentos($sistemas, $empresa, $proveedor);

        $global = $this->getJson('/api/mi-calificacion-global', $this->cabecerasComo($usuario, $empresa))
            ->assertOk()
            ->json();

        // Los componentes que no se pudieron medir NO van en
        // 'componentes': salen aparte, sin puntaje, como nota al pie.
        $sinDatos = collect($global['componentes_sin_datos'])->pluck('clave');
        $medidos = collect($global['componentes'])->pluck('clave');

        $this->assertTrue(
            $sinDatos->contains('fill_rate'),
            'Sin pedidos cerrados el fill rate no se mide: no puede puntuar 0.'
        );
        $this->assertFalse($medidos->contains('fill_rate'));

        // La documentación SÍ se mide (está completa y aprobada) y, al
        // renormalizarse el peso de lo no medido, se lleva la nota entera.
        $this->assertTrue($medidos->contains('documentos'));
        $this->assertEqualsWithDelta(
            100.0,
            (float) $global['puntaje_total'],
            0.01,
            'Lo único medible está al 100%, así que la nota es 100.'
        );
    }

    /**
     * El rol Calidad es el que recibe los productos que Compras dejó pasar.
     * Este test comprueba que de verdad pueda trabajarlos: aprobarlos uno
     * por uno Y cerrar la calificación.
     */
    public function test_calidad_puede_trabajar_los_productos_que_le_paso_compras(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        $producto = Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => UnidadPresentacion::firstOrFail()->Id_Unidad_Presentacion,
            'Nombre_Producto' => 'PRODUCTO PARA CALIDAD',
            'Activo' => 1,
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Pendiente',
            'Etapa_Aprobacion' => Producto::ETAPA_CALIDAD,
            'Fecha_Creacion' => now(),
        ]);

        // 1. Verlos.
        $this->getJson("/api/proveedores/{$proveedor->Id_Proveedor}/productos-calificacion", $cabeceras)
            ->assertOk();

        // 2. Aprobarlos.
        $this->postJson(
            "/api/proveedores/productos-calificacion/{$producto->Id_Producto}",
            ['aprobado' => true],
            $cabeceras
        )->assertOk();

        // 3. Cerrar la calificación.
        $this->postJson(
            "/api/proveedores/{$proveedor->Id_Proveedor}/productos-calificacion/registrar",
            [],
            $cabeceras
        )->assertOk();
    }

    /**
     * El otro lado de lo mismo: abrirle los productos a Calidad no le
     * abre la ficha ni los documentos del proveedor. Sin este test,
     * ampliar el permiso de productos mañana podría llevarse puesta esa
     * separación sin que nadie se entere.
     */
    public function test_calidad_sigue_sin_poder_calificar_la_ficha_ni_los_documentos(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        $this->postJson(
            "/api/proveedores/{$proveedor->Id_Proveedor}/ficha-calificacion",
            ['aprobado' => true],
            $cabeceras
        )->assertForbidden();

        $this->getJson("/api/proveedores/{$proveedor->Id_Proveedor}/documentos-calificacion", $cabeceras)
            ->assertForbidden();

        $this->postJson(
            "/api/proveedores/{$proveedor->Id_Proveedor}/documentos-calificacion/registrar",
            [],
            $cabeceras
        )->assertForbidden();
    }
}
