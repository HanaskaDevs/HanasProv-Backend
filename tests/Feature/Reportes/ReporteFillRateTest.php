<?php

namespace Tests\Feature\Reportes;

use App\Models\Empresa;
use App\Modules\Pedidos\Models\DetallePedidoCompra;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\CalificacionGlobalService;
use Tests\TestCase;

/**
 * Reporte de Cumplimiento de entregas con filtros (05-nov-2026, pedido de
 * Calidad): fecha de recepción desde/hasta (la semana es un rango
 * lunes-domingo) y proveedor; las fechas, hasta 5 días atrás.
 * Fechas relativas a hoy: con el límite, una fecha fija vencería sola. El fill rate es el MISMO cálculo que la nota:
 * promedio del % de cada pedido cerrado, sin que una sobre-entrega tape un
 * faltante.
 */
class ReporteFillRateTest extends TestCase
{
    private const URL = '/api/proveedores/reporte-fill-rate';

    /** Pedido con líneas [cantidad, recibida] y su fecha de recepción. */
    private function pedido(Proveedor $proveedor, string $estado, string $fechaRecepcion, array $lineas): PedidoCompra
    {
        $pedido = PedidoCompra::create([
            'Id_Empresa' => $proveedor->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nro_Pedido' => 'FR-'.random_int(100000, 999999),
            'Fecha_Registro_BC' => '2026-10-01',
            'Fecha_Recepcion_Esperada' => $fechaRecepcion,
            'Fecha_Sincronizacion' => now(),
            'Activo' => 1,
            'Estado' => $estado,
        ]);

        foreach ($lineas as $i => [$cantidad, $recibida]) {
            DetallePedidoCompra::create([
                'Id_Pedido_Compra' => $pedido->Id_Pedido_Compra,
                'Nro_Linea' => ($i + 1) * 10000,
                'Codigo_Producto' => 'COD-'.$i,
                'Descripcion' => 'Linea '.$i,
                'Cantidad' => $cantidad,
                'Cantidad_Recibida' => $recibida,
            ]);
        }

        return $pedido;
    }

    private function hace(int $dias): string
    {
        return now()->subDays($dias)->toDateString();
    }

    /** @return array{0: Empresa, 1: array, 2: Proveedor, 3: Proveedor} */
    private function escenario(): array
    {
        $empresa = $this->crearEmpresa();
        [, $a] = $this->crearProveedorConUsuario($empresa);
        [, $b] = $this->crearProveedorConUsuario($empresa);

        // Rango de prueba: de hace 5 a hace 2 días; hoy queda como "otro rango".
        $this->pedido($a, 'Cerrado', $this->hace(5), [[10, 10]]);          // 100
        $this->pedido($a, 'Cerrado', $this->hace(2), [[10, 5]]);           // 50
        $this->pedido($a, 'Cerrado', $this->hace(0), [[10, 0]]);           // 0, fuera del rango
        $this->pedido($a, 'Abierto', $this->hace(3), [[10, 0]]);           // abierto: no cuenta
        $this->pedido($b, 'Cerrado', $this->hace(4), [[10, 20], [10, 0]]); // 50: la sobre-entrega no tapa

        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Calidad'), $empresa);

        return [$empresa, $cabeceras, $a, $b];
    }

    private function filaDe(array $json, Proveedor $proveedor): ?array
    {
        return collect($json['proveedores'])->firstWhere('id_proveedor', $proveedor->Id_Proveedor);
    }

    public function test_filtra_por_fecha_de_recepcion_y_promedia_por_pedido(): void
    {
        [, $cabeceras, $a, $b] = $this->escenario();

        $json = $this->getJson(self::URL.'?desde='.$this->hace(5).'&hasta='.$this->hace(2), $cabeceras)->assertOk()->json();

        $this->assertSame(2, $this->filaDe($json, $a)['pedidos']);
        $this->assertEqualsWithDelta(75.0, $this->filaDe($json, $a)['porcentaje'], 0.01);
        $this->assertEqualsWithDelta(50.0, $this->filaDe($json, $b)['porcentaje'], 0.01);
        $this->assertSame([], $json['detalle'], 'Sin proveedor elegido no se manda el detalle.');

        // Solo hoy: el pedido al 0% de A; B queda sin pedidos.
        $siguiente = $this->getJson(self::URL.'?desde='.$this->hace(0).'&hasta='.$this->hace(0), $cabeceras)->assertOk()->json();
        $this->assertEqualsWithDelta(0.0, $this->filaDe($siguiente, $a)['porcentaje'], 0.01);
        $this->assertNull($this->filaDe($siguiente, $b));
        $this->assertContains($b->fresh()->Razon_Social, $siguiente['sin_pedidos']);
    }

    public function test_con_proveedor_trae_solo_ese_y_el_detalle_de_sus_pedidos(): void
    {
        [, $cabeceras, $a, $b] = $this->escenario();

        $json = $this->getJson(self::URL.'?desde='.$this->hace(5).'&hasta='.$this->hace(2).'&id_proveedor='.$a->Id_Proveedor, $cabeceras)
            ->assertOk()->json();

        $this->assertCount(1, $json['proveedores']);
        $this->assertNull($this->filaDe($json, $b));
        $this->assertSame([$this->hace(5), $this->hace(2)], array_column($json['detalle'], 'fecha_recepcion'));
        $this->assertEqualsWithDelta(50.0, $json['detalle'][1]['porcentaje'], 0.01);
        $this->assertEqualsWithDelta(5.0, $json['detalle'][1]['recibida'], 0.01);
    }

    public function test_sin_filtros_da_lo_mismo_que_la_nota(): void
    {
        [, $cabeceras, $a] = $this->escenario();

        $json = $this->getJson(self::URL, $cabeceras)->assertOk()->json();
        $nota = collect(app(CalificacionGlobalService::class)->calcular($a->fresh())['componentes'])
            ->firstWhere('clave', 'fill_rate');

        $this->assertSame(3, $this->filaDe($json, $a)['pedidos']);
        $this->assertEqualsWithDelta($nota['porcentaje'], $this->filaDe($json, $a)['porcentaje'], 0.01);
    }

    public function test_hasta_no_puede_ser_anterior_a_desde(): void
    {
        [, $cabeceras] = $this->escenario();

        $this->getJson(self::URL.'?desde='.$this->hace(1).'&hasta='.$this->hace(3), $cabeceras)->assertStatus(422);
    }

    public function test_solo_se_puede_filtrar_hasta_5_dias_atras(): void
    {
        [, $cabeceras] = $this->escenario();

        // Justo 5 días: entra. 6 días, o una fecha futura: no.
        $this->getJson(self::URL.'?desde='.$this->hace(5), $cabeceras)->assertOk();
        $this->getJson(self::URL.'?hasta='.$this->hace(1), $cabeceras)->assertOk();
        $this->getJson(self::URL.'?desde='.$this->hace(6), $cabeceras)
            ->assertStatus(422)->assertJsonValidationErrors('desde');
        $this->getJson(self::URL.'?hasta='.$this->hace(6), $cabeceras)->assertStatus(422);
        $this->getJson(self::URL.'?hasta='.now()->addDay()->toDateString(), $cabeceras)->assertStatus(422);
    }

    public function test_permisos_guardia_no_y_proveedor_de_otra_empresa_no(): void
    {
        [$empresa] = $this->escenario();

        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');
        $this->getJson(self::URL, $this->cabecerasComo($guardia, $empresa))->assertForbidden();

        // cabecerasComo() otra vez y no las del escenario: Sanctum se queda con
        // el último usuario resuelto (el guardia) hasta que se olvidan los guards.
        $calidad = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Calidad'), $empresa);
        [, $ajeno] = $this->crearProveedorConUsuario($this->crearEmpresa());
        $this->getJson(self::URL.'?id_proveedor='.$ajeno->Id_Proveedor, $calidad)->assertNotFound();
    }
}
