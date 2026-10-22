<?php

namespace Tests\Feature\Productos;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Ficha_Productos\Models\DocumentoProducto;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\TipoDocumentoProducto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Proveedores\Models\Proveedor;
use Tests\TestCase;

/**
 * Categoría del producto (22-oct-2026): Perecibles, No Perecibles o
 * Fruver. En Fruver, la "Notificación sanitaria o Certificado de
 * Inscripción de alimentos" (NSANITARIA) deja de ser obligatoria.
 */
class ClasificacionProductoTest extends TestCase
{
    private function idUnidad(): int
    {
        return (int) UnidadPresentacion::where('Activo', 1)->value('Id_Unidad_Presentacion');
    }

    private function producto(Proveedor $proveedor, ?string $clasificacion): Producto
    {
        return Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => 'PRODUCTO '.($clasificacion ?? 'SIN CATEGORIA'),
            'Clasificacion' => $clasificacion,
            'Activo' => 1,
            'Bloqueado' => 0,
            'Fecha_Creacion' => now(),
        ]);
    }

    /** Sube (directo en la base) un documento de cada tipo obligatorio, salvo los indicados. */
    private function cargarObligatorios(Producto $producto, Usuario $usuario, array $salvoCodigos = []): void
    {
        $tipos = TipoDocumentoProducto::where('Activo', 1)->where('Obligatorio', 1)->get()
            ->reject(fn (TipoDocumentoProducto $t) => in_array($t->Codigo_Archivo, $salvoCodigos, true));

        foreach ($tipos as $tipo) {
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

            DocumentoProducto::create([
                'Id_Producto' => $producto->Id_Producto,
                'Id_Tipo_Documento_Producto' => $tipo->Id_Tipo_Documento_Producto,
                'Id_Archivo' => $archivo->Id_Archivo,
                'Activo' => 1,
                'Fecha_Creacion' => now(),
            ]);
        }
    }

    /** @return array{0: Usuario, 1: Proveedor, 2: Empresa} */
    private function proveedor(): array
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        return [$usuario, $proveedor, $empresa];
    }

    public function test_la_regla_de_obligatoriedad(): void
    {
        $nsanitaria = TipoDocumentoProducto::where('Codigo_Archivo', 'NSANITARIA')->firstOrFail();
        $fichaTecnica = TipoDocumentoProducto::where('Codigo_Archivo', 'FT')->firstOrFail();

        $this->assertTrue($nsanitaria->esObligatorioPara(Producto::PERECIBLES));
        $this->assertTrue($nsanitaria->esObligatorioPara(Producto::NO_PERECIBLES));
        $this->assertFalse($nsanitaria->esObligatorioPara(Producto::FRUVER));
        // Sin categoría vale la regla general, la más exigente.
        $this->assertTrue($nsanitaria->esObligatorioPara(null));
        // El resto de los obligatorios sigue igual en Fruver.
        $this->assertTrue($fichaTecnica->esObligatorioPara(Producto::FRUVER));
    }

    public function test_crear_un_producto_exige_la_categoria(): void
    {
        [$usuario, , $empresa] = $this->proveedor();
        $cabeceras = $this->cabecerasComo($usuario, $empresa);
        $datos = ['nombre_producto' => 'Manzana', 'id_unidad_presentacion' => $this->idUnidad()];

        $this->postJson('/api/mis-productos', $datos, $cabeceras)
            ->assertStatus(422)
            ->assertJsonValidationErrors('clasificacion');

        $this->postJson('/api/mis-productos', [...$datos, 'clasificacion' => 'Fruver'], $cabeceras)
            ->assertCreated()
            ->assertJsonPath('clasificacion', 'Fruver');
    }

    public function test_un_producto_fruver_se_registra_sin_la_notificacion_sanitaria(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $fruver = $this->producto($proveedor, Producto::FRUVER);
        $this->cargarObligatorios($fruver, $usuario, salvoCodigos: ['NSANITARIA']);

        $this->postJson('/api/mis-productos/registrar', ['ids' => [$fruver->Id_Producto]], $this->cabecerasComo($usuario, $empresa))
            ->assertOk();

        $this->assertTrue((bool) $fruver->fresh()->Bloqueado);
    }

    public function test_un_producto_perecible_sigue_exigiendo_la_notificacion_sanitaria(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $perecible = $this->producto($proveedor, Producto::PERECIBLES);
        $this->cargarObligatorios($perecible, $usuario, salvoCodigos: ['NSANITARIA']);

        $this->postJson('/api/mis-productos/registrar', ['ids' => [$perecible->Id_Producto]], $this->cabecerasComo($usuario, $empresa))
            ->assertStatus(422);

        $this->assertFalse((bool) $perecible->fresh()->Bloqueado);
    }

    /** Un producto viejo sin categoría no se puede enviar, aunque tenga todos los documentos. */
    public function test_sin_categoria_no_se_puede_registrar(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $viejo = $this->producto($proveedor, null);
        $this->cargarObligatorios($viejo, $usuario);
        $cabeceras = $this->cabecerasComo($usuario, $empresa);

        $this->getJson('/api/mis-productos/resumen-registro?ids='.$viejo->Id_Producto, $cabeceras)
            ->assertOk()
            ->assertJsonPath('puede_registrar', false);

        $this->postJson('/api/mis-productos/registrar', ['ids' => [$viejo->Id_Producto]], $cabeceras)
            ->assertStatus(422)
            ->assertJsonFragment(['El producto "'.$viejo->Nombre_Producto.'" no tiene categoría (Perecibles, No Perecibles o Fruver).']);
    }

    /** Lo que pide el modal de documentos a los productos viejos. */
    public function test_se_le_puede_asignar_la_categoria_a_un_producto_existente(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $viejo = $this->producto($proveedor, null);
        $cabeceras = $this->cabecerasComo($usuario, $empresa);

        $this->putJson("/api/mis-productos/{$viejo->Id_Producto}/clasificacion", ['clasificacion' => 'Inventada'], $cabeceras)
            ->assertStatus(422);

        $this->putJson("/api/mis-productos/{$viejo->Id_Producto}/clasificacion", ['clasificacion' => 'No_Perecibles'], $cabeceras)
            ->assertOk()
            ->assertJsonPath('clasificacion', 'No_Perecibles');
    }

    /** Uno que ya está en revisión no se cambia por este atajo. */
    public function test_no_se_cambia_la_categoria_de_un_producto_en_revision(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $enRevision = $this->producto($proveedor, null);
        $enRevision->forceFill(['Bloqueado' => 1, 'Estado_Calificacion' => 'Pendiente'])->save();

        $this->putJson("/api/mis-productos/{$enRevision->Id_Producto}/clasificacion", ['clasificacion' => 'Fruver'], $this->cabecerasComo($usuario, $empresa))
            ->assertForbidden();
    }

    /** En Fruver la Notificación sanitaria es opcional: se puede borrar. */
    public function test_en_fruver_la_notificacion_sanitaria_se_puede_borrar(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedor();
        $fruver = $this->producto($proveedor, Producto::FRUVER);
        $perecible = $this->producto($proveedor, Producto::PERECIBLES);
        $this->cargarObligatorios($fruver, $usuario);
        $this->cargarObligatorios($perecible, $usuario);
        $cabeceras = $this->cabecerasComo($usuario, $empresa);
        $idNs = TipoDocumentoProducto::where('Codigo_Archivo', 'NSANITARIA')->value('Id_Tipo_Documento_Producto');

        $docFruver = DocumentoProducto::where('Id_Producto', $fruver->Id_Producto)->where('Id_Tipo_Documento_Producto', $idNs)->firstOrFail();
        $docPerecible = DocumentoProducto::where('Id_Producto', $perecible->Id_Producto)->where('Id_Tipo_Documento_Producto', $idNs)->firstOrFail();

        $this->deleteJson("/api/mis-productos/documentos/{$docPerecible->Id_Documento_Producto}", [], $cabeceras)->assertStatus(422);
        $this->deleteJson("/api/mis-productos/documentos/{$docFruver->Id_Documento_Producto}", [], $cabeceras)->assertSuccessful();
    }

    public function test_el_catalogo_de_tipos_dice_cuales_son_opcionales_en_fruver(): void
    {
        [$usuario, , $empresa] = $this->proveedor();

        $tipos = collect($this->getJson('/api/mis-productos/tipos-documento', $this->cabecerasComo($usuario, $empresa))->assertOk()->json());
        $nsanitaria = $tipos->first(fn ($t) => str_starts_with($t['nombre_documento'], 'Notificación sanitaria'));

        $this->assertTrue($nsanitaria['obligatorio']);
        $this->assertFalse($nsanitaria['obligatorio_en_fruver']);
    }
}
