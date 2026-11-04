<?php

namespace Tests\Feature\Productos;

use App\Models\Empresa;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 04-nov-2026, FROZENTROPIC en producción: un producto que vino de Business
 * Central se "eliminaba" y volvía a aparecer, porque al recargar la lista
 * sincronizarDesdeBC() lo volvía a crear con otro id (y sin la categoría que
 * se le había puesto). Ahora los productos de BC se desactivan en vez de
 * borrarse, y la sincronización no los recrea.
 */
class EliminarProductoDeBcTest extends TestCase
{
    /** @return array{0: Empresa, 1: Proveedor, 2: string} empresa con código BC, proveedor y Nro_Producto en el espejo BC */
    private function proveedorConProductoEnBc(): array
    {
        $empresaBc = 'TST'.Str::upper(Str::random(6));
        $empresa = $this->crearEmpresa(['Empresa_BC' => $empresaBc]);
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $nroProveedor = 'PRV-'.Str::upper(Str::random(6));
        $nroProducto = 'PRD-'.Str::upper(Str::random(6));

        DB::table('BC_Ficha_Proveedor')->insert([
            'Empresa' => $empresaBc, 'Fecha_Registro' => now(), 'Nro_Proveedor' => $nroProveedor,
            'Nombre' => 'PROVEEDOR BC DE PRUEBA', 'Nro_Identificacion' => $proveedor->Ruc,
        ]);
        DB::table('BC_Producto_Proveedor')->insert([
            'Empresa' => $empresaBc, 'Fecha_Registro' => now(), 'Nro_Producto' => $nroProducto,
            'Nro_Proveedor' => $nroProveedor, 'Cod_Unidad_Medida' => 'UN', 'Costo_Unitario_Directo' => 1.5,
        ]);
        DB::table('BC_Ficha_Producto')->insert([
            'Empresa' => $empresaBc, 'Fecha_Registro' => now(), 'Nro_Producto' => $nroProducto,
            'Descripcion' => 'Producto de BC de prueba', 'GTIN' => null,
        ]);

        return [$empresa, $proveedor, $nroProducto];
    }

    public function test_un_producto_de_bc_eliminado_no_vuelve_a_aparecer(): void
    {
        [$empresa, $proveedor, $nroProducto] = $this->proveedorConProductoEnBc();
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Compras'), $empresa);
        $base = "/api/productos-proveedor/{$proveedor->Id_Proveedor}";

        // Al abrir la lista, la sincronización lo trae de BC.
        $ids = collect($this->getJson($base, $cabeceras)->assertOk()->json('data'))->pluck('id_producto');
        $this->assertCount(1, $ids);
        $idProducto = $ids->first();

        $this->deleteJson("{$base}/{$idProducto}", [], $cabeceras)->assertOk();

        // Se recarga la lista (vuelve a sincronizar): no reaparece ni se crea otro.
        $this->assertCount(0, $this->getJson($base, $cabeceras)->assertOk()->json('data'));
        $this->assertSame(1, Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)->where('Bc_Nro_Producto', $nroProducto)->count());
        $this->assertFalse((bool) Producto::find($idProducto)->Activo);
    }

    public function test_el_borrado_masivo_de_productos_de_bc_tampoco_los_recrea(): void
    {
        [$empresa, $proveedor] = $this->proveedorConProductoEnBc();
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Compras'), $empresa);
        $base = "/api/productos-proveedor/{$proveedor->Id_Proveedor}";

        $idProducto = $this->getJson($base, $cabeceras)->assertOk()->json('data.0.id_producto');
        $this->deleteJson("{$base}/masivo", ['ids' => [$idProducto]], $cabeceras)->assertOk()->assertJsonPath('total', 1);

        $this->assertCount(0, $this->getJson($base, $cabeceras)->assertOk()->json('data'));
        $this->assertSame(1, Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)->count());
    }

    public function test_un_producto_cargado_a_mano_se_sigue_borrando_de_verdad(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Compras'), $empresa);
        $producto = Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => UnidadPresentacion::where('Activo', 1)->value('Id_Unidad_Presentacion'),
            'Nombre_Producto' => 'PRODUCTO A MANO',
            'Activo' => 1,
            'Bloqueado' => 0,
            'Fecha_Creacion' => now(),
        ]);

        $this->deleteJson("/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$producto->Id_Producto}", [], $cabeceras)->assertOk();

        $this->assertNull(Producto::find($producto->Id_Producto));
    }

    public function test_la_categoria_de_un_producto_de_bc_queda_guardada_al_recargar(): void
    {
        [$empresa, $proveedor] = $this->proveedorConProductoEnBc();
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Compras'), $empresa);
        $base = "/api/productos-proveedor/{$proveedor->Id_Proveedor}";

        $idProducto = $this->getJson($base, $cabeceras)->assertOk()->json('data.0.id_producto');
        $this->putJson("{$base}/{$idProducto}/clasificacion", ['clasificacion' => Producto::NO_PERECIBLES], $cabeceras)->assertOk();

        $this->assertSame(Producto::NO_PERECIBLES, $this->getJson($base, $cabeceras)->assertOk()->json('data.0.clasificacion'));
    }
}
