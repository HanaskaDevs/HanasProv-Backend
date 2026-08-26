<?php

namespace Tests\Feature\Pedidos;

use App\Modules\Asistente\Services\AsistenteContextoService;
use App\Modules\Pedidos\Models\DetallePedidoCompra;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Pedidos\Services\PedidoService;
use Tests\TestCase;

/**
 * Las dos pestañas de Pedidos del proveedor (Vigentes / Históricos) son
 * complementos EXACTOS: ningún pedido puede salir en las dos ni
 * desaparecer de ambas. La clasificación no mira el campo Estado, sino
 * la fecha efectiva (recepción esperada, o registro si BC no la mandó) y
 * si ya se entregó todo.
 */
class PedidoVistasTest extends TestCase
{
    private function crearPedido(
        int $idEmpresa,
        int $idProveedor,
        string $fechaRecepcion,
        float $cantidad = 10,
        float $recibida = 0
    ): PedidoCompra {
        $pedido = PedidoCompra::create([
            'Id_Empresa' => $idEmpresa,
            'Id_Proveedor' => $idProveedor,
            'Nro_Pedido' => 'TEST-'.random_int(100000, 999999),
            'Fecha_Registro_BC' => now()->subDays(10)->toDateString(),
            'Fecha_Recepcion_Esperada' => $fechaRecepcion,
            'Estado' => 'Abierto',
            'Activo' => 1,
        ]);

        DetallePedidoCompra::create([
            'Id_Pedido_Compra' => $pedido->Id_Pedido_Compra,
            'Nro_Linea' => 10000,
            'Codigo_Producto' => 'PROD-1',
            'Descripcion' => 'Producto de prueba',
            'Cantidad' => $cantidad,
            'Cantidad_Recibida' => $recibida,
        ]);

        return $pedido;
    }

    public function test_un_pedido_futuro_sin_entregar_va_a_vigentes(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $pedido = $this->crearPedido(
            $empresa->Id_Empresa,
            $proveedor->Id_Proveedor,
            now()->addDays(5)->toDateString()
        );

        $servicio = app(PedidoService::class);

        $vigentes = $servicio->listar($usuario, $empresa->Id_Empresa, 'vigentes');
        $historicos = $servicio->listar($usuario, $empresa->Id_Empresa, 'historicos');

        $this->assertTrue($vigentes->contains('Id_Pedido_Compra', $pedido->Id_Pedido_Compra));
        $this->assertFalse($historicos->contains('Id_Pedido_Compra', $pedido->Id_Pedido_Compra));
    }

    public function test_un_pedido_con_fecha_pasada_va_a_historicos(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $pedido = $this->crearPedido(
            $empresa->Id_Empresa,
            $proveedor->Id_Proveedor,
            now()->subDays(3)->toDateString()
        );

        $servicio = app(PedidoService::class);

        $this->assertTrue(
            $servicio->listar($usuario, $empresa->Id_Empresa, 'historicos')
                ->contains('Id_Pedido_Compra', $pedido->Id_Pedido_Compra)
        );
        $this->assertFalse(
            $servicio->listar($usuario, $empresa->Id_Empresa, 'vigentes')
                ->contains('Id_Pedido_Compra', $pedido->Id_Pedido_Compra)
        );
    }

    /**
     * Entregado al 100% pasa a Históricos aunque la fecha no haya llegado:
     * si ya se recibió todo, no hay nada que esperar.
     */
    public function test_entregado_completo_va_a_historicos_aunque_la_fecha_no_haya_llegado(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $pedido = $this->crearPedido(
            $empresa->Id_Empresa,
            $proveedor->Id_Proveedor,
            now()->addDays(5)->toDateString(),
            cantidad: 10,
            recibida: 10
        );

        $servicio = app(PedidoService::class);

        $this->assertTrue(
            $servicio->listar($usuario, $empresa->Id_Empresa, 'historicos')
                ->contains('Id_Pedido_Compra', $pedido->Id_Pedido_Compra)
        );
        $this->assertFalse(
            $servicio->listar($usuario, $empresa->Id_Empresa, 'vigentes')
                ->contains('Id_Pedido_Compra', $pedido->Id_Pedido_Compra)
        );
    }

    /**
     * REGRESIÓN: listar() acepta SOLO la vista ('vigentes'/'historicos').
     * Pasarle un valor del campo Estado ('Abierto') tiene que explotar, no
     * devolver algo en silencio -> eso es exactamente lo que hacía el
     * asistente Hana y por eso nunca podía informar pedidos (ver el test
     * de abajo).
     */
    public function test_una_vista_invalida_falla_fuerte(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa);

        $this->expectException(\InvalidArgumentException::class);

        app(PedidoService::class)->listar($usuario, $empresa->Id_Empresa, 'Abierto');
    }

    /**
     * REGRESIÓN del bug del bot: el contexto que se le manda al modelo
     * tiene que traer los pedidos REALES del proveedor. Antes le pasaba
     * 'Abierto'/'Cerrado' a listar(), el catch se comía la excepción y
     * todos los proveedores recibían "No se pudo obtener el estado de
     * pedidos" para siempre.
     */
    public function test_el_contexto_del_asistente_informa_los_pedidos_del_proveedor(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->crearPedido($empresa->Id_Empresa, $proveedor->Id_Proveedor, now()->addDays(4)->toDateString());
        $this->crearPedido($empresa->Id_Empresa, $proveedor->Id_Proveedor, now()->subDays(4)->toDateString());

        $contexto = app(AsistenteContextoService::class)->generar($usuario, $empresa->Id_Empresa);

        $this->assertStringContainsString('Vigentes: 1. Históricos: 1.', $contexto);
        // Lo que de verdad cuida este test: que NO se haya caído al mensaje
        // de error del catch. Si el contexto se reformula otra vez, esta
        // aserción sigue siendo la que detecta la regresión.
        $this->assertStringNotContainsString('no se pudo leer el estado de pedidos', $contexto);
    }
}
