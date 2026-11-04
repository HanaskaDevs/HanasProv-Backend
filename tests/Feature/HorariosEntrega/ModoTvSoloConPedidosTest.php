<?php

namespace Tests\Feature\HorariosEntrega;

use App\Models\Empresa;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Modo TV (04-nov-2026): con solo_con_pedidos=1 aparecen solo los proveedores
 * con algún pedido registrado en BC entre hace 7 días y hoy. Sin el parámetro
 * (Seguimiento de hoy) se siguen viendo todos los que tienen horario hoy.
 */
class ModoTvSoloConPedidosTest extends TestCase
{
    private function horarioDeHoy(Empresa $empresa, Proveedor $proveedor): HorarioEntregaProveedor
    {
        $diaDeHoy = [
            0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles',
            4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado',
        ][now()->dayOfWeek];

        return HorarioEntregaProveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => 'Perecibles',
            'Dia_Entrega' => $diaDeHoy,
            'Anden_Puerta' => 'Andén de prueba',
            'Hora_Llegada' => '23:50',
            'Tiempo_Permanencia_Min' => 5,
            'Hora_Salida' => '23:55',
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);
    }

    private function pedido(Empresa $empresa, Proveedor $proveedor, int $haceDias): void
    {
        PedidoCompra::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nro_Pedido' => 'TV-'.random_int(100000, 999999),
            'Fecha_Registro_BC' => now()->subDays($haceDias)->toDateString(),
            'Fecha_Sincronizacion' => now(),
            'Activo' => 1,
            'Estado' => 'Abierto',
        ]);
    }

    private function pedidoSoloEnBc(Empresa $empresa, Proveedor $proveedor, int $haceDias): void
    {
        $nroProveedor = 'PTV-'.random_int(100000, 999999);

        DB::table('BC_Ficha_Proveedor')->insert([
            'Empresa' => $empresa->Empresa_BC,
            'Fecha_Registro' => now(),
            'Nro_Proveedor' => $nroProveedor,
            'Nombre' => 'PROVEEDOR TV DE PRUEBA',
            'Nro_Identificacion' => $proveedor->Ruc,
        ]);
        DB::table('BC_Cab_Pedido_Compra')->insert([
            'Empresa' => $empresa->Empresa_BC,
            'Fecha_Registro' => now(),
            'Tipo_Documento' => 'Pedido',
            'Nro_Pedido' => 'PTV-'.random_int(100000, 999999),
            'Nro_Proveedor' => $nroProveedor,
            'Nombre_Proveedor' => 'PROVEEDOR TV DE PRUEBA',
            'Fecha_Recepcion_Esperada' => now()->startOfDay(),
            'Fecha_Registro_BC' => now()->subDays($haceDias)->startOfDay(),
            'Estado_Pedido' => 'Lanzado',
            'Cod_Almacen' => 'CD-0001',
        ]);
    }

    public function test_solo_aparecen_los_proveedores_con_pedidos_de_los_ultimos_7_dias(): void
    {
        $empresa = $this->crearEmpresa();
        [, $conPedidoHoy] = $this->crearProveedorConUsuario($empresa);
        [, $conPedidoHace7] = $this->crearProveedorConUsuario($empresa);
        [, $conPedidoViejo] = $this->crearProveedorConUsuario($empresa);
        [, $sinPedidos] = $this->crearProveedorConUsuario($empresa);

        $hHoy = $this->horarioDeHoy($empresa, $conPedidoHoy);
        $h7 = $this->horarioDeHoy($empresa, $conPedidoHace7);
        $hViejo = $this->horarioDeHoy($empresa, $conPedidoViejo);
        $hSin = $this->horarioDeHoy($empresa, $sinPedidos);

        $this->pedido($empresa, $conPedidoHoy, 0);
        $this->pedido($empresa, $conPedidoHace7, 7);   // el límite entra
        $this->pedido($empresa, $conPedidoViejo, 8);   // un día de más: no entra

        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Sistemas'), $empresa);

        $ids = collect($this->getJson('/api/horarios-entrega/hoy?incluir_recibidos=1&solo_con_pedidos=1', $cabeceras)
            ->assertOk()->json())->pluck('id_horario_entrega_proveedor')->all();

        $this->assertContains($hHoy->Id_Horario_Entrega_Proveedor, $ids);
        $this->assertContains($h7->Id_Horario_Entrega_Proveedor, $ids);
        $this->assertNotContains($hViejo->Id_Horario_Entrega_Proveedor, $ids);
        $this->assertNotContains($hSin->Id_Horario_Entrega_Proveedor, $ids);

        // Fuente principal: el espejo de BC (Pedido_Compra dejó de llenarse el
        // 27-oct). Un pedido de hace 3 días solo en BC_Cab_Pedido_Compra, con
        // el proveedor enganchado por RUC en BC_Ficha_Proveedor.
        [, $soloEnBc] = $this->crearProveedorConUsuario($empresa);
        [, $soloEnBcViejo] = $this->crearProveedorConUsuario($empresa);
        $hBc = $this->horarioDeHoy($empresa, $soloEnBc);
        $hBcViejo = $this->horarioDeHoy($empresa, $soloEnBcViejo);
        $this->pedidoSoloEnBc($empresa, $soloEnBc, 3);
        $this->pedidoSoloEnBc($empresa, $soloEnBcViejo, 8);

        $ids = collect($this->getJson('/api/horarios-entrega/hoy?incluir_recibidos=1&solo_con_pedidos=1', $cabeceras)
            ->assertOk()->json())->pluck('id_horario_entrega_proveedor')->all();
        $this->assertContains($hBc->Id_Horario_Entrega_Proveedor, $ids);
        $this->assertNotContains($hBcViejo->Id_Horario_Entrega_Proveedor, $ids);
        $this->assertContains($hHoy->Id_Horario_Entrega_Proveedor, $ids);

        // Seguimiento de hoy (sin el parámetro) sigue mostrando a todos.
        $todos = collect($this->getJson('/api/horarios-entrega/hoy', $cabeceras)
            ->assertOk()->json())->pluck('id_horario_entrega_proveedor')->all();
        $this->assertContains($hSin->Id_Horario_Entrega_Proveedor, $todos);
        $this->assertContains($hViejo->Id_Horario_Entrega_Proveedor, $todos);
    }
}
