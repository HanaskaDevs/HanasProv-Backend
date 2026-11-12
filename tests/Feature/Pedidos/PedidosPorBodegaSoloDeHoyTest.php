<?php

namespace Tests\Feature\Pedidos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pedidos por bodega abre SIEMPRE en las entregas de HOY (12-nov-2026).
 *
 * La pantalla no manda ninguna fecha, así que el valor por defecto lo pone el
 * controller. Antes, sin fecha, se listaba el espejo entero y se veían
 * entregas de días pasados mezcladas con las del día.
 *
 * Quien sí manda una fecha (Hana, o la pantalla el día que tenga un selector)
 * sigue viendo la que pida: eso también se prueba acá.
 */
class PedidosPorBodegaSoloDeHoyTest extends TestCase
{
    /** Pedido de una línea en CD-0001 con la fecha de entrega que se le diga. */
    private function pedidoBc(string $empresaBc, string $nroPedido, string $fechaEsperada): void
    {
        $base = ['Empresa' => $empresaBc, 'Fecha_Registro' => now(), 'Tipo_Documento' => 'Pedido', 'Nro_Pedido' => $nroPedido];

        DB::table('BC_Cab_Pedido_Compra')->insert([
            ...$base,
            'Nro_Proveedor' => 'PRV-TEST',
            'Nombre_Proveedor' => 'PROVEEDOR BC DE PRUEBA',
            'Fecha_Recepcion_Esperada' => $fechaEsperada,
            'Fecha_Registro_BC' => $fechaEsperada,
            'Estado_Pedido' => 'Lanzado',
            'Cod_Almacen' => 'CD-0001',
        ]);
        DB::table('BC_Det_Pedido_Compra')->insert([
            ...$base,
            'Nro_Linea' => 10000,
            'Nro_Producto' => 'PRD-TEST',
            'Descripcion' => 'Producto de prueba',
            'Cod_Almacen' => 'CD-0001',
            'Cantidad' => 10,
        ]);
    }

    /** @return array{0: string, 1: string, 2: array<string, string>} */
    private function escenario(): array
    {
        $empresaBc = 'TST'.Str::upper(Str::random(6));
        $empresa = $this->crearEmpresa(['Empresa_BC' => $empresaBc]);

        $deHoy = 'PC-'.Str::upper(Str::random(8));
        $deAyer = 'PC-'.Str::upper(Str::random(8));

        $this->pedidoBc($empresaBc, $deHoy, now()->toDateString());
        $this->pedidoBc($empresaBc, $deAyer, now()->subDay()->toDateString());

        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Admin'), $empresa);

        return [$deHoy, $deAyer, $cabeceras];
    }

    public function test_sin_fecha_solo_se_ven_las_entregas_de_hoy(): void
    {
        [$deHoy, $deAyer, $cabeceras] = $this->escenario();

        $nros = collect($this->getJson('/api/pedidos/internos?ocultar_completos=1', $cabeceras)
            ->assertOk()
            ->json('CD-0001.pedidos'))->pluck('nro_pedido')->all();

        $this->assertContains($deHoy, $nros);
        $this->assertNotContains($deAyer, $nros);
    }

    public function test_si_se_pide_otra_fecha_se_respeta(): void
    {
        [$deHoy, $deAyer, $cabeceras] = $this->escenario();
        $ayer = now()->subDay()->toDateString();

        $nros = collect($this->getJson("/api/pedidos/internos?fecha_recepcion_esperada={$ayer}", $cabeceras)
            ->assertOk()
            ->json('CD-0001.pedidos'))->pluck('nro_pedido')->all();

        $this->assertContains($deAyer, $nros);
        $this->assertNotContains($deHoy, $nros);
    }
}
