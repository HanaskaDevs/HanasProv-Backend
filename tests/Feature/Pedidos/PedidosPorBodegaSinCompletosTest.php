<?php

namespace Tests\Feature\Pedidos;

use App\Models\Empresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pedidos por bodega (04-nov-2026): la pantalla ya no muestra los pedidos
 * entregados al 100%, pero el % de la bodega se sigue calculando con todos y
 * se informa cuántos se ocultaron. Sin el filtro (Hana) sigue viendo todos.
 */
class PedidosPorBodegaSinCompletosTest extends TestCase
{
    /** Pedido de una línea en CD-0001 para hoy, con lo pedido y lo recibido. */
    private function pedidoBc(string $empresaBc, string $nroPedido, float $cantidad, float $recibida): void
    {
        $base = ['Empresa' => $empresaBc, 'Fecha_Registro' => now(), 'Tipo_Documento' => 'Pedido', 'Nro_Pedido' => $nroPedido];

        DB::table('BC_Cab_Pedido_Compra')->insert([
            ...$base,
            'Nro_Proveedor' => 'PRV-TEST',
            'Nombre_Proveedor' => 'PROVEEDOR BC DE PRUEBA',
            'Fecha_Recepcion_Esperada' => now()->startOfDay(),
            'Fecha_Registro_BC' => now()->startOfDay(),
            'Estado_Pedido' => 'Lanzado',
            'Cod_Almacen' => 'CD-0001',
        ]);
        DB::table('BC_Det_Pedido_Compra')->insert([
            ...$base,
            'Nro_Linea' => 10000,
            'Nro_Producto' => 'PRD-TEST',
            'Descripcion' => 'Producto de prueba',
            'Cod_Almacen' => 'CD-0001',
            'Cantidad' => $cantidad,
        ]);
        DB::table('BC_Pedido_Compra_Fill_Rate')->insert([
            'Empresa' => $empresaBc,
            'Fecha_Registro' => now(),
            'Nro_Documento' => $nroPedido,
            'Nro_Linea' => 10000,
            'Cantidad_Original' => $cantidad,
            'Cantidad_Recibida' => $recibida,
        ]);
    }

    /** @return array{0: Empresa, 1: string, 2: string, 3: array<string, string>} */
    private function escenario(): array
    {
        $empresaBc = 'TST'.Str::upper(Str::random(6));
        $empresa = $this->crearEmpresa(['Empresa_BC' => $empresaBc]);
        $completo = 'PC-'.Str::upper(Str::random(8));
        $pendiente = 'PC-'.Str::upper(Str::random(8));

        $this->pedidoBc($empresaBc, $completo, 10, 10);   // 100%
        $this->pedidoBc($empresaBc, $pendiente, 10, 5);   // 50%

        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Admin'), $empresa);

        return [$empresa, $completo, $pendiente, $cabeceras];
    }

    public function test_la_pantalla_no_muestra_los_entregados_al_100(): void
    {
        [, $completo, $pendiente, $cabeceras] = $this->escenario();
        $hoy = now()->toDateString();

        $bodega = $this->getJson("/api/pedidos/internos?fecha_recepcion_esperada={$hoy}&ocultar_completos=1", $cabeceras)
            ->assertOk()
            ->json('CD-0001');

        $nros = collect($bodega['pedidos'])->pluck('nro_pedido');
        $this->assertContains($pendiente, $nros->all());
        $this->assertNotContains($completo, $nros->all());
        $this->assertSame(1, $bodega['pedidos_completos_ocultos']);
        // El % de la bodega sigue contando el pedido completo: (100 + 50) / 2.
        $this->assertEquals(75, $bodega['porcentaje_entrega']);
    }

    public function test_sin_el_filtro_se_ven_todos(): void
    {
        [, $completo, $pendiente, $cabeceras] = $this->escenario();
        $hoy = now()->toDateString();

        $nros = collect($this->getJson("/api/pedidos/internos?fecha_recepcion_esperada={$hoy}", $cabeceras)
            ->assertOk()
            ->json('CD-0001.pedidos'))->pluck('nro_pedido')->all();

        $this->assertContains($completo, $nros);
        $this->assertContains($pendiente, $nros);
    }
}
