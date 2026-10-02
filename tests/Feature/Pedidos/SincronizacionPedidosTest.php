<?php

namespace Tests\Feature\Pedidos;

use App\Models\Empresa;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Pedidos\Services\PedidoInternoService;
use App\Modules\Pedidos\Services\SincronizacionPedidosService;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sincronización de pedidos desde las tablas espejo de BC (02-oct-2026).
 *
 * Lo que más se protege acá es que el proceso AGUANTE EL VOLUMEN. Antes,
 * la consulta descartaba los pedidos ya importados mandando la lista
 * entera como parámetros: 824 y subiendo ~20 por día. SQL Server admite
 * 2100 por consulta, así que en un par de meses la sincronización se
 * habría caído sola, en silencio y para toda la empresa.
 */
class SincronizacionPedidosTest extends TestCase
{
    private Empresa $empresa;

    private Proveedor $proveedor;

    private string $empresaBc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresaBc = 'bc'.Str::lower(Str::random(6));
        $this->empresa = $this->crearEmpresa(['Empresa_BC' => $this->empresaBc]);
        [, $this->proveedor] = $this->crearProveedorConUsuario($this->empresa);

        DB::table('BC_Ficha_Proveedor')->insert([
            'Empresa' => $this->empresaBc,
            'Fecha_Registro' => now()->format('Y-m-d\TH:i:s'),
            'Nro_Proveedor' => 'PRV-TEST',
            'Nombre' => $this->proveedor->Razon_Social,
            'Nro_Identificacion' => $this->proveedor->Ruc,
        ]);
    }

    /** Un pedido en BC, con sus líneas, dentro de la ventana. */
    private function pedidoEnBc(int $lineas = 2, int $diasAtras = 0): string
    {
        $nroPedido = 'PED-'.Str::upper(Str::random(10));
        $ahora = now()->format('Y-m-d\TH:i:s');
        $registroBc = now()->subDays($diasAtras)->format('Y-m-d\TH:i:s');

        DB::table('BC_Cab_Pedido_Compra')->insert([
            'Empresa' => $this->empresaBc,
            'Fecha_Registro' => $ahora,
            'Tipo_Documento' => 'Order',
            'Nro_Pedido' => $nroPedido,
            'Nro_Proveedor' => 'PRV-TEST',
            'Nombre_Proveedor' => $this->proveedor->Razon_Social,
            'Fecha_Recepcion_Esperada' => now()->addDays(2)->format('Y-m-d\TH:i:s'),
            'Fecha_Registro_BC' => $registroBc,
            'Estado_Pedido' => 'Released',
        ]);

        for ($i = 1; $i <= $lineas; $i++) {
            DB::table('BC_Det_Pedido_Compra')->insert([
                'Empresa' => $this->empresaBc,
                'Fecha_Registro' => $ahora,
                'Tipo_Documento' => 'Order',
                'Nro_Pedido' => $nroPedido,
                'Nro_Linea' => $i * 10000,
                'Nro_Producto' => "ART-{$i}",
                'Descripcion' => "Artículo {$i}",
                'Cod_Almacen' => 'CD-0006',
                'Cantidad' => 100,
            ]);
        }

        return $nroPedido;
    }

    private function sincronizar(): int
    {
        return app(SincronizacionPedidosService::class)->sincronizar($this->empresa->Id_Empresa);
    }

    public function test_baja_el_pedido_con_sus_lineas(): void
    {
        $nroPedido = $this->pedidoEnBc(lineas: 3);

        $this->assertSame(1, $this->sincronizar());

        $pedido = PedidoCompra::where('Id_Empresa', $this->empresa->Id_Empresa)
            ->where('Nro_Pedido', $nroPedido)
            ->firstOrFail();

        $this->assertEquals($this->proveedor->Id_Proveedor, $pedido->Id_Proveedor);
        $this->assertSame('Abierto', $pedido->Estado);
        $this->assertSame(3, $pedido->lineas()->count());
    }

    /** Correr cuatro veces al día no puede duplicar nada. */
    public function test_correrlo_de_nuevo_no_trae_ni_duplica(): void
    {
        $nroPedido = $this->pedidoEnBc();

        $this->assertSame(1, $this->sincronizar());
        $this->assertSame(0, $this->sincronizar(), 'La segunda corrida no debería traer nada.');

        $this->assertSame(
            1,
            PedidoCompra::where('Id_Empresa', $this->empresa->Id_Empresa)->where('Nro_Pedido', $nroPedido)->count()
        );
    }

    /**
     * EL CASO QUE ROMPÍA: con muchos pedidos ya importados, la consulta
     * mandaba uno por uno como parámetro. Acá se comprueba que ninguna
     * consulta se acerque al tope de SQL Server, por más pedidos locales
     * que haya.
     */
    public function test_ninguna_consulta_se_acerca_al_tope_de_parametros(): void
    {
        // Muchos pedidos ya importados: antes, cada uno sumaba un parámetro.
        for ($i = 0; $i < 60; $i++) {
            PedidoCompra::create([
                'Id_Empresa' => $this->empresa->Id_Empresa,
                'Id_Proveedor' => $this->proveedor->Id_Proveedor,
                'Nro_Pedido' => 'VIEJO-'.Str::upper(Str::random(8)),
                'Fecha_Registro_BC' => now(),
                'Estado_Pedido_BC' => 'Released',
                'Estado' => 'Abierto',
                'Fecha_Sincronizacion' => now(),
                'Activo' => 1,
            ]);
        }

        $this->pedidoEnBc();

        DB::enableQueryLog();
        $this->sincronizar();
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $maximo = max(array_map(fn ($c) => count($c['bindings']), $consultas));

        // Con 60 pedidos locales, la consulta de selección tendría 60
        // parámetros extra con el método viejo. Ahora no depende de eso.
        $this->assertLessThan(
            100,
            $maximo,
            'Alguna consulta está mandando la lista de pedidos existentes como parámetros.'
        );
    }

    /** La ventana es la red de seguridad: fuera de ella no lo trae nadie. */
    public function test_un_pedido_fuera_de_la_ventana_no_se_trae(): void
    {
        config(['portal.pedidos.dias_ventana' => 7]);
        $this->pedidoEnBc(diasAtras: 20);

        $this->assertSame(0, $this->sincronizar());
    }

    /** Y ampliando la ventana, el mismo pedido sí entra. */
    public function test_ampliar_la_ventana_recupera_un_pedido_atrasado(): void
    {
        $nroPedido = $this->pedidoEnBc(diasAtras: 20);

        config(['portal.pedidos.dias_ventana' => 30]);

        $this->assertSame(1, $this->sincronizar());
        $this->assertTrue(
            PedidoCompra::where('Id_Empresa', $this->empresa->Id_Empresa)->where('Nro_Pedido', $nroPedido)->exists()
        );
    }

    /** Solo los 'Released': un pedido en borrador no es un compromiso. */
    public function test_no_baja_pedidos_que_no_esten_released(): void
    {
        $nroPedido = $this->pedidoEnBc();

        DB::table('BC_Cab_Pedido_Compra')
            ->where('Empresa', $this->empresaBc)
            ->where('Nro_Pedido', $nroPedido)
            ->update(['Estado_Pedido' => 'Open']);

        $this->assertSame(0, $this->sincronizar());
    }

    // ---- Bodegas -----------------------------------------------------

    /** CD-0006 entró el 02-oct-2026. */
    public function test_cd_0006_es_una_bodega_valida(): void
    {
        $this->assertContains('CD-0006', PedidoInternoService::BODEGAS);
    }

    /**
     * La lista de bodegas tiene que ser UNA SOLA. Cuando estaba copiada en
     * UsuarioService, habilitar una bodega nueva la mostraba en Pedidos
     * Internos pero Sistemas no podía asignársela a nadie.
     */
    public function test_sistemas_puede_asignar_la_bodega_nueva(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $compras = $this->crearUsuarioInterno($this->empresa, 'Compras');

        $this->putJson(
            "/api/usuarios/{$compras->Id_Usuario}/empresas/{$this->empresa->Id_Empresa}/bodegas",
            ['codigos_bodega' => ['CD-0006']],
            $this->cabecerasComo($sistemas, $this->empresa)
        )->assertOk();

        $this->assertSame(['CD-0006'], $compras->fresh()->codigosBodegasAsignadas($this->empresa->Id_Empresa));
    }

    public function test_un_codigo_de_bodega_inventado_se_rechaza(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $compras = $this->crearUsuarioInterno($this->empresa, 'Compras');

        $this->putJson(
            "/api/usuarios/{$compras->Id_Usuario}/empresas/{$this->empresa->Id_Empresa}/bodegas",
            ['codigos_bodega' => ['CD-9999']],
            $this->cabecerasComo($sistemas, $this->empresa)
        )->assertStatus(422);
    }

    /** Compras ve solo las suyas; Admin y Sistemas, todas. */
    public function test_las_bodegas_permitidas_dependen_del_rol(): void
    {
        $servicio = app(PedidoInternoService::class);
        $admin = $this->crearUsuarioInterno($this->empresa, 'Admin');
        $compras = $this->crearUsuarioInterno($this->empresa, 'Compras');

        $this->assertSame(
            PedidoInternoService::BODEGAS,
            $servicio->obtenerBodegasPermitidas($admin, $this->empresa->Id_Empresa)
        );

        // Sin asignaciones todavía: no ve ninguna.
        $this->assertSame([], $servicio->obtenerBodegasPermitidas($compras, $this->empresa->Id_Empresa));
    }
}
