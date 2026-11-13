<?php

namespace Tests\Feature\Productos;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Ficha_Productos\Mail\AvisoProductoMail;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Ficha_Productos\Services\AvisoProductosService;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Primer paso de la revisión de productos repartido por código de Business
 * Central (13-nov-2026, pedido del usuario):
 *
 *  - producto SIN código BC (nuevo para Hanaska) -> lo revisa COMPRAS;
 *  - producto CON código BC                      -> lo revisa CATÁLOGO.
 *
 * Admin, Sistemas y Calidad siguen resolviendo cualquiera. Después del
 * primer paso todo sigue igual: pasa a Calidad.
 */
class RevisionPorCodigoBcTest extends TestCase
{
    private function idUnidad(): int
    {
        return (int) (UnidadPresentacion::where('Nombre_Unidad', 'Unidad')->value('Id_Unidad_Presentacion')
            ?? UnidadPresentacion::where('Activo', 1)->value('Id_Unidad_Presentacion'));
    }

    /** Producto enviado por el proveedor y esperando el primer paso. */
    private function pendiente(Proveedor $proveedor, ?string $codigoBc, string $nombre = 'PRODUCTO DE PRUEBA'): Producto
    {
        return Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->idUnidad(),
            'Nombre_Producto' => $nombre,
            'Clasificacion' => Producto::PERECIBLES,
            'Bc_Nro_Producto' => $codigoBc,
            'Activo' => 1,
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Pendiente',
            'Etapa_Aprobacion' => Producto::ETAPA_COMPRAS,
            'Fecha_Creacion' => now(),
        ]);
    }

    /** @return array{0: Empresa, 1: Proveedor, 2: Producto, 3: Producto, 4: Usuario} */
    private function escenario(): array
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor, $proveedor] = $this->crearProveedorConUsuario($empresa);

        return [
            $empresa,
            $proveedor,
            $this->pendiente($proveedor, 'PRD-PRUEBA-1', 'CON CODIGO BC'),
            $this->pendiente($proveedor, null, 'NUEVO SIN CODIGO'),
            $usuarioProveedor,
        ];
    }

    /** @return array<int, int> */
    private function bandeja(array $cabeceras): array
    {
        return collect($this->getJson('/api/productos-revision', $cabeceras)->assertOk()->json())
            ->pluck('id_producto')->all();
    }

    public function test_catalogo_ve_y_aprueba_solo_los_que_tienen_codigo_bc(): void
    {
        [$empresa, , $conCodigo, $sinCodigo] = $this->escenario();
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Catálogo'), $empresa);

        $ids = $this->bandeja($cabeceras);
        $this->assertContains($conCodigo->Id_Producto, $ids);
        $this->assertNotContains($sinCodigo->Id_Producto, $ids);

        $this->postJson("/api/productos-revision/{$conCodigo->Id_Producto}/aprobar", [], $cabeceras)->assertOk();
        $this->assertSame(Producto::ETAPA_CALIDAD, $conCodigo->fresh()->Etapa_Aprobacion);
    }

    public function test_catalogo_no_resuelve_los_productos_nuevos(): void
    {
        [$empresa, , , $sinCodigo] = $this->escenario();
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Catálogo'), $empresa);

        $this->postJson("/api/productos-revision/{$sinCodigo->Id_Producto}/aprobar", [], $cabeceras)->assertForbidden();
        $this->postJson("/api/productos-revision/{$sinCodigo->Id_Producto}/rechazar", ['observacion' => 'No corresponde este producto'], $cabeceras)->assertForbidden();
        $this->postJson("/api/productos-revision/{$sinCodigo->Id_Producto}/eliminar", ['observacion' => 'No corresponde este producto'], $cabeceras)->assertForbidden();
        $this->postJson('/api/productos-revision/aprobar-varios', ['productos' => [$sinCodigo->Id_Producto]], $cabeceras)->assertForbidden();

        $this->assertSame(Producto::ETAPA_COMPRAS, $sinCodigo->fresh()->Etapa_Aprobacion);
        $this->assertSame('Pendiente', $sinCodigo->fresh()->Estado_Calificacion);
    }

    public function test_compras_ve_y_aprueba_solo_los_productos_nuevos(): void
    {
        [$empresa, , $conCodigo, $sinCodigo] = $this->escenario();
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Compras'), $empresa);

        $ids = $this->bandeja($cabeceras);
        $this->assertContains($sinCodigo->Id_Producto, $ids);
        $this->assertNotContains($conCodigo->Id_Producto, $ids);

        $this->postJson("/api/productos-revision/{$conCodigo->Id_Producto}/aprobar", [], $cabeceras)->assertForbidden();
        $this->postJson("/api/productos-revision/{$conCodigo->Id_Producto}/rechazar", ['observacion' => 'No corresponde este producto'], $cabeceras)->assertForbidden();
        $this->assertSame(Producto::ETAPA_COMPRAS, $conCodigo->fresh()->Etapa_Aprobacion);

        $this->postJson("/api/productos-revision/{$sinCodigo->Id_Producto}/aprobar", [], $cabeceras)->assertOk();
        $this->assertSame(Producto::ETAPA_CALIDAD, $sinCodigo->fresh()->Etapa_Aprobacion);
    }

    public function test_aprobar_varios_mezclando_los_dos_no_aprueba_ninguno(): void
    {
        [$empresa, , $conCodigo, $sinCodigo] = $this->escenario();
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Compras'), $empresa);

        $this->postJson('/api/productos-revision/aprobar-varios', ['productos' => [$conCodigo->Id_Producto, $sinCodigo->Id_Producto]], $cabeceras)
            ->assertForbidden();

        $this->assertSame(Producto::ETAPA_COMPRAS, $conCodigo->fresh()->Etapa_Aprobacion);
        $this->assertSame(Producto::ETAPA_COMPRAS, $sinCodigo->fresh()->Etapa_Aprobacion);
    }

    public function test_admin_ve_los_dos_y_la_api_dice_quien_revisa_cada_uno(): void
    {
        [$empresa, , $conCodigo, $sinCodigo] = $this->escenario();
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Admin'), $empresa);

        $filas = collect($this->getJson('/api/productos-revision', $cabeceras)->assertOk()->json())->keyBy('id_producto');

        $this->assertSame('Catálogo', $filas[$conCodigo->Id_Producto]['revisa_primer_paso']);
        $this->assertSame('PRD-PRUEBA-1', $filas[$conCodigo->Id_Producto]['bc_nro_producto']);
        $this->assertSame('Compras', $filas[$sinCodigo->Id_Producto]['revisa_primer_paso']);
        $this->assertNull($filas[$sinCodigo->Id_Producto]['bc_nro_producto']);
    }

    public function test_el_aviso_de_envio_va_a_catalogo_o_a_compras_segun_el_codigo(): void
    {
        Mail::fake();

        [$empresa, , $conCodigo, $sinCodigo, $usuarioProveedor] = $this->escenario();
        $catalogo = $this->crearUsuarioInterno($empresa, 'Catálogo');
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        app(AvisoProductosService::class)->avisarACompras(
            collect([$conCodigo, $sinCodigo])->map->load('proveedor'),
            $usuarioProveedor
        );

        Mail::assertQueued(AvisoProductoMail::class, fn ($m) => $m->hasTo($catalogo->Email) && ! $m->hasTo($compras->Email)
            && str_contains($m->nombreProducto, 'CON CODIGO BC'));
        Mail::assertQueued(AvisoProductoMail::class, fn ($m) => $m->hasTo($compras->Email) && ! $m->hasTo($catalogo->Email)
            && str_contains($m->nombreProducto, 'NUEVO SIN CODIGO'));
    }
}
