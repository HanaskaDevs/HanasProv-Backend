<?php

namespace Tests\Feature\HorariosEntrega;

use App\Modules\Pedidos\Models\PedidoCompra;
use Tests\TestCase;

/**
 * El calendario del proveedor (sección Pedidos) trae, por cada día, los
 * números de sus pedidos abiertos para la próxima fecha de ese día
 * (27-oct-2026).
 */
class MisHorariosPedidosTest extends TestCase
{
    public function test_cada_dia_trae_los_pedidos_abiertos_de_esa_fecha(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        [, $otro] = $this->crearProveedorConUsuario($empresa);

        // Horario con el día de hoy y un pedido abierto con recepción hoy.
        $this->crearEntrega($empresa, $proveedor, habilitado: false);
        $abierto = PedidoCompra::where('Id_Proveedor', $proveedor->Id_Proveedor)->firstOrFail();

        // No deben salir: uno cerrado de hoy y uno de otro proveedor.
        $cerrado = PedidoCompra::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nro_Pedido' => 'TEST-CERRADO-'.random_int(1000, 9999),
            'Fecha_Registro_BC' => now()->subDays(3)->toDateString(),
            'Fecha_Recepcion_Esperada' => now()->toDateString(),
            'Fecha_Sincronizacion' => now(),
            'Activo' => 1,
            'Estado' => 'Cerrado',
        ]);
        $this->crearEntrega($empresa, $otro, calendario: false, habilitado: false);

        $respuesta = $this->getJson('/api/horarios-entrega/mios', $this->cabecerasComo($usuario, $empresa))->assertOk();

        $dia = collect($respuesta->json())->first();
        $this->assertSame(now()->toDateString(), $dia['proxima_fecha']);
        $this->assertSame([$abierto->Nro_Pedido], $dia['pedidos']);
        $this->assertNotContains($cerrado->Nro_Pedido, $dia['pedidos']);
    }

    public function test_un_dia_sin_pedidos_trae_la_lista_vacia(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor, now()->addDays(2), pedido: false, habilitado: false);

        $respuesta = $this->getJson('/api/horarios-entrega/mios', $this->cabecerasComo($usuario, $empresa))->assertOk();

        $dia = collect($respuesta->json())->first();
        $this->assertSame(now()->addDays(2)->toDateString(), $dia['proxima_fecha']);
        $this->assertSame([], $dia['pedidos']);
    }
}
