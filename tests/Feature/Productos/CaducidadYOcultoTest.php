<?php

namespace Tests\Feature\Productos;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Ficha_Productos\Models\DocumentoProducto;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\TipoDocumentoProducto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Cambios del 23-oct-2026:
 *  - el Análisis de producto pide fecha de caducidad; Calidad completa la de
 *    los ya cargados y no puede aprobar sin ella;
 *  - "ocultar al proveedor" desde Productos por proveedor;
 *  - la hora de salida del calendario se calcula sola.
 */
class CaducidadYOcultoTest extends TestCase
{
    private function idUnidad(): int
    {
        return (int) UnidadPresentacion::where('Activo', 1)->value('Id_Unidad_Presentacion');
    }

    private function producto(Proveedor $proveedor, array $extra = []): Producto
    {
        return Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => 'PRODUCTO DE PRUEBA',
            'Clasificacion' => Producto::PERECIBLES,
            'Activo' => 1,
            'Bloqueado' => 0,
            'Fecha_Creacion' => now(),
            ...$extra,
        ]);
    }

    /** Un documento de cada tipo obligatorio; el análisis SIN fecha, como los viejos. */
    private function documentosSinFechaEnAnalisis(Producto $producto, Usuario $usuario): DocumentoProducto
    {
        $analisis = null;

        foreach (TipoDocumentoProducto::where('Activo', 1)->where('Obligatorio', 1)->get() as $tipo) {
            $archivo = Archivo::create([
                'Id_Proveedor' => $producto->Id_Proveedor,
                'Nombre_Original' => 'doc.pdf',
                'Ruta_Almacenamiento' => 'pruebas/'.uniqid().'.pdf',
                'Hash_Archivo' => hash('sha256', uniqid('', true)),
                'Tipo_Mime' => 'application/pdf',
                'Tamano_Bytes' => 1024,
                'Categoria_Archivo' => $tipo->Carpeta_Slug,
                'Id_Usuario_Carga' => $usuario->Id_Usuario,
                'Fecha_Carga' => now(),
                'Activo' => 1,
            ]);

            $doc = DocumentoProducto::create([
                'Id_Producto' => $producto->Id_Producto,
                'Id_Tipo_Documento_Producto' => $tipo->Id_Tipo_Documento_Producto,
                'Id_Archivo' => $archivo->Id_Archivo,
                // Solo el análisis queda sin fecha; el resto, con una vigente.
                'Fecha_Caducidad' => $tipo->Codigo_Archivo === 'AP' ? null : ($tipo->Requiere_Fecha_Caducidad ? now()->addYear()->toDateString() : null),
                'Activo' => 1,
                'Fecha_Creacion' => now(),
            ]);

            if ($tipo->Codigo_Archivo === 'AP') {
                $analisis = $doc;
            }
        }

        return $analisis;
    }

    /** @return array{0: Usuario, 1: Proveedor, 2: Empresa} */
    private function proveedor(): array
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        return [$usuario, $proveedor, $empresa];
    }

    // ---------- Análisis de producto con caducidad ----------

    public function test_el_analisis_de_producto_pide_fecha_al_subirlo(): void
    {
        $this->assertTrue((bool) TipoDocumentoProducto::where('Codigo_Archivo', 'AP')->value('Requiere_Fecha_Caducidad'));

        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $producto = $this->producto($proveedor);
        $idAnalisis = TipoDocumentoProducto::where('Codigo_Archivo', 'AP')->value('Id_Tipo_Documento_Producto');

        $this->post(
            "/api/mis-productos/{$producto->Id_Producto}/documentos/{$idAnalisis}",
            ['archivo' => UploadedFile::fake()->create('analisis.pdf', 100, 'application/pdf')],
            $this->cabecerasComo($usuario, $empresa)
        )->assertStatus(422);
    }

    public function test_calidad_no_aprueba_sin_la_fecha_del_analisis_y_la_puede_completar(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $producto = $this->producto($proveedor, ['Bloqueado' => 1, 'Estado_Calificacion' => 'Pendiente', 'Etapa_Aprobacion' => Producto::ETAPA_CALIDAD]);
        $analisis = $this->documentosSinFechaEnAnalisis($producto, $usuario);
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        // La pantalla de Calidad ve que falta.
        $doc = collect($this->getJson("/api/proveedores/{$proveedor->Id_Proveedor}/productos-calificacion", $cabeceras)->assertOk()->json('productos.0.documentos'))
            ->firstWhere('id_documento_producto', $analisis->Id_Documento_Producto);
        $this->assertTrue($doc['requiere_fecha_caducidad']);
        $this->assertNull($doc['fecha_caducidad']);

        $this->postJson("/api/proveedores/productos-calificacion/{$producto->Id_Producto}", ['aprobado' => true], $cabeceras)
            ->assertStatus(422);

        // Devolverlo sí se puede, aunque falte la fecha: se prueba en otro producto.
        $fecha = now()->addMonths(8)->toDateString();
        $this->putJson("/api/proveedores/productos-calificacion/documento/{$analisis->Id_Documento_Producto}/fecha-caducidad", ['fecha_caducidad' => $fecha], $cabeceras)
            ->assertOk()
            ->assertJsonPath('fecha_caducidad', $fecha);

        $this->postJson("/api/proveedores/productos-calificacion/{$producto->Id_Producto}", ['aprobado' => true], $cabeceras)
            ->assertOk();
        $this->assertSame('Aprobado', $producto->fresh()->Estado_Calificacion);
    }

    public function test_sin_la_fecha_del_analisis_se_puede_devolver(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $producto = $this->producto($proveedor, ['Bloqueado' => 1, 'Estado_Calificacion' => 'Pendiente', 'Etapa_Aprobacion' => Producto::ETAPA_CALIDAD]);
        $this->documentosSinFechaEnAnalisis($producto, $usuario);

        $this->postJson(
            "/api/proveedores/productos-calificacion/{$producto->Id_Producto}",
            ['aprobado' => false, 'observacion' => 'Falta el análisis vigente'],
            $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Calidad'), $empresa)
        )->assertOk();
    }

    public function test_el_proveedor_no_completa_fechas_por_la_calificacion(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $producto = $this->producto($proveedor);
        $analisis = $this->documentosSinFechaEnAnalisis($producto, $usuario);

        $this->putJson(
            "/api/proveedores/productos-calificacion/documento/{$analisis->Id_Documento_Producto}/fecha-caducidad",
            ['fecha_caducidad' => now()->addYear()->toDateString()],
            $this->cabecerasComo($usuario, $empresa)
        )->assertForbidden();
    }

    // ---------- Ocultar al proveedor ----------

    public function test_un_producto_oculto_desaparece_para_el_proveedor_y_queda_para_los_internos(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        $visible = $this->producto($proveedor, ['Nombre_Producto' => 'VISIBLE']);
        $oculto = $this->producto($proveedor, ['Nombre_Producto' => 'OCULTO']);
        // Encabezados nuevos en cada cambio de usuario: el guard recuerda al
        // último (ver TestCase::cabecerasComo).
        $comoProveedor = fn () => $this->cabecerasComo($usuario, $empresa);

        $this->putJson("/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$oculto->Id_Producto}/visibilidad", ['oculto' => true], $this->cabecerasComo($compras, $empresa))
            ->assertOk()
            ->assertJsonPath('oculto_proveedor', true);

        $delProveedor = collect($this->getJson('/api/mis-productos', $comoProveedor())->assertOk()->json('data'))->pluck('id_producto');
        $this->assertTrue($delProveedor->contains($visible->Id_Producto));
        $this->assertFalse($delProveedor->contains($oculto->Id_Producto));

        // Ni entrando directo por su id.
        $this->putJson("/api/mis-productos/{$oculto->Id_Producto}", [
            'nombre_producto' => 'INTENTO', 'id_unidad_presentacion' => $this->idUnidad(), 'clasificacion' => 'Perecibles',
        ], $comoProveedor())->assertNotFound();

        $deCompras = collect($this->getJson("/api/productos-proveedor/{$proveedor->Id_Proveedor}", $this->cabecerasComo($compras, $empresa))->assertOk()->json('data'));
        $this->assertTrue((bool) $deCompras->firstWhere('id_producto', $oculto->Id_Producto)['oculto_proveedor']);

        // Y se puede volver a mostrar.
        $this->putJson("/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$oculto->Id_Producto}/visibilidad", ['oculto' => false], $this->cabecerasComo($compras, $empresa))
            ->assertOk();
        $this->assertTrue(collect($this->getJson('/api/mis-productos', $comoProveedor())->json('data'))->pluck('id_producto')->contains($oculto->Id_Producto));
    }

    public function test_el_proveedor_no_puede_ocultar_ni_mostrar(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $producto = $this->producto($proveedor);

        $this->putJson("/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$producto->Id_Producto}/visibilidad", ['oculto' => true], $this->cabecerasComo($usuario, $empresa))
            ->assertForbidden();
    }

    // ---------- Hora de salida automática ----------

    public function test_la_hora_de_salida_se_calcula_con_la_permanencia(): void
    {
        [, $proveedor, $empresa] = $this->proveedor();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $this->postJson('/api/horarios-entrega', [
            'id_proveedor' => $proveedor->Id_Proveedor,
            'clasificacion' => 'Perecibles',
            'dia_entrega' => 'Lunes',
            'hora_llegada' => '08:45',
            'tiempo_permanencia_min' => 90,
            // Lo que mande la pantalla se ignora: la salida se calcula.
            'hora_salida' => '23:59',
        ], $this->cabecerasComo($sistemas, $empresa))->assertCreated();

        $this->assertSame('10:15', HorarioEntregaProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)->value('Hora_Salida'));
    }
}
