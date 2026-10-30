<?php

namespace Tests\Feature\Productos;

use App\Models\Empresa;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\TipoDocumentoProducto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Rol Catálogo (29-oct-2026): Catálogo de productos, Productos por proveedor
 * y Productos por revisar. Edita los DATOS de cualquier producto, esté o no
 * aprobado, sin cambiarle el estado; no carga documentos, no crea ni elimina
 * productos y no aprueba ni rechaza.
 */
class RolCatalogoTest extends TestCase
{
    private function idUnidad(): int
    {
        return (int) (UnidadPresentacion::where('Nombre_Unidad', 'Unidad')->value('Id_Unidad_Presentacion')
            ?? UnidadPresentacion::where('Activo', 1)->value('Id_Unidad_Presentacion'));
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

    /** @return array{0: Empresa, 1: Proveedor, 2: array<string, string>} */
    private function escenario(): array
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $catalogo = $this->crearUsuarioInterno($empresa, 'Catálogo');

        return [$empresa, $proveedor, $this->cabecerasComo($catalogo, $empresa)];
    }

    private function datos(string $nombre): array
    {
        return [
            'nombre_producto' => $nombre,
            'clasificacion' => Producto::PERECIBLES,
            'codigo_barras' => '7861234567890',
            'id_unidad_presentacion' => $this->idUnidad(),
        ];
    }

    public function test_entra_al_catalogo_de_productos(): void
    {
        [, , $cabeceras] = $this->escenario();

        $this->getJson('/api/catalogo-productos', $cabeceras)->assertOk();
    }

    public function test_ve_los_productos_de_un_proveedor(): void
    {
        [, $proveedor, $cabeceras] = $this->escenario();
        $this->producto($proveedor);

        $this->getJson('/api/productos-proveedor/proveedores', $cabeceras)->assertOk();
        $this->getJson("/api/productos-proveedor/{$proveedor->Id_Proveedor}", $cabeceras)->assertOk();
        $this->getJson("/api/productos-proveedor/{$proveedor->Id_Proveedor}/resumen-registro", $cabeceras)->assertOk();
    }

    public function test_edita_un_producto_aprobado_y_sigue_aprobado(): void
    {
        [, $proveedor, $cabeceras] = $this->escenario();
        $producto = $this->producto($proveedor, ['Bloqueado' => 1, 'Estado_Calificacion' => 'Aprobado']);

        $this->putJson("/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$producto->Id_Producto}", $this->datos('nombre corregido'), $cabeceras)
            ->assertOk();

        $producto->refresh();
        $this->assertSame('NOMBRE CORREGIDO', $producto->Nombre_Producto);
        $this->assertSame('Aprobado', $producto->Estado_Calificacion, 'Catálogo no devuelve el producto a calificación.');
        $this->assertTrue((bool) $producto->Bloqueado);
    }

    public function test_edita_un_producto_en_revision_sin_cambiarle_el_estado(): void
    {
        [, $proveedor, $cabeceras] = $this->escenario();
        $producto = $this->producto($proveedor, [
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Pendiente',
            'Etapa_Aprobacion' => Producto::ETAPA_COMPRAS,
        ]);

        $this->putJson("/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$producto->Id_Producto}", $this->datos('en revision'), $cabeceras)
            ->assertOk();

        $producto->refresh();
        $this->assertSame('EN REVISION', $producto->Nombre_Producto);
        $this->assertSame('Pendiente', $producto->Estado_Calificacion);
        $this->assertSame(Producto::ETAPA_COMPRAS, $producto->Etapa_Aprobacion);
    }

    public function test_para_compras_editar_un_aprobado_lo_sigue_devolviendo_a_calificacion(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        $producto = $this->producto($proveedor, ['Bloqueado' => 1, 'Estado_Calificacion' => 'Aprobado']);

        $this->putJson("/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$producto->Id_Producto}", $this->datos('otro nombre'), $this->cabecerasComo($compras, $empresa))
            ->assertOk();

        $this->assertSame('Pendiente', $producto->fresh()->Estado_Calificacion);
    }

    public function test_no_crea_ni_elimina_ni_registra_ni_oculta(): void
    {
        [, $proveedor, $cabeceras] = $this->escenario();
        $producto = $this->producto($proveedor);
        $base = "/api/productos-proveedor/{$proveedor->Id_Proveedor}";

        $this->postJson($base, $this->datos('nuevo'), $cabeceras)->assertForbidden();
        $this->deleteJson("{$base}/{$producto->Id_Producto}", [], $cabeceras)->assertForbidden();
        $this->postJson("{$base}/registrar", ['ids' => [$producto->Id_Producto]], $cabeceras)->assertForbidden();
        $this->deleteJson("{$base}/masivo", ['ids' => [$producto->Id_Producto]], $cabeceras)->assertForbidden();
        $this->putJson("{$base}/{$producto->Id_Producto}/visibilidad", ['oculto' => true], $cabeceras)->assertForbidden();

        $this->assertNotNull($producto->fresh());
    }

    public function test_no_sube_documentos(): void
    {
        [, $proveedor, $cabeceras] = $this->escenario();
        $producto = $this->producto($proveedor);
        $idTipo = TipoDocumentoProducto::where('Activo', 1)->value('Id_Tipo_Documento_Producto');

        $this->post(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$producto->Id_Producto}/documentos/{$idTipo}",
            [
                'archivo' => UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n%prueba\n%%EOF"),
                'fecha_caducidad' => now()->addYear()->toDateString(),
                'nombre_documento' => 'Documento de prueba',
            ],
            $cabeceras
        )->assertForbidden();
    }

    public function test_ve_productos_por_revisar_pero_no_aprueba_ni_rechaza(): void
    {
        [, $proveedor, $cabeceras] = $this->escenario();
        $producto = $this->producto($proveedor, [
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Pendiente',
            'Etapa_Aprobacion' => Producto::ETAPA_COMPRAS,
        ]);

        $ids = collect($this->getJson('/api/productos-revision', $cabeceras)->assertOk()->json())->pluck('id_producto');
        $this->assertContains($producto->Id_Producto, $ids->all());

        $this->postJson("/api/productos-revision/{$producto->Id_Producto}/aprobar", [], $cabeceras)->assertForbidden();
        $this->postJson("/api/productos-revision/{$producto->Id_Producto}/rechazar", ['observacion' => 'No corresponde este producto'], $cabeceras)->assertForbidden();
        $this->postJson('/api/productos-revision/aprobar-varios', ['productos' => [$producto->Id_Producto]], $cabeceras)->assertForbidden();

        $this->assertSame(Producto::ETAPA_COMPRAS, $producto->fresh()->Etapa_Aprobacion);
    }

    public function test_no_ve_la_calificacion_de_proveedores(): void
    {
        [, $proveedor, $cabeceras] = $this->escenario();

        $this->getJson("/api/proveedores/{$proveedor->Id_Proveedor}/calificacion-global", $cabeceras)->assertForbidden();
    }
}
