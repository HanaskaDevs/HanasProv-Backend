<?php

namespace App\Modules\Pedidos\Services;

use App\Models\Empresa;
use App\Modules\Pedidos\Models\DetallePedidoCompra;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trae pedidos "Released" desde las tablas locales de staging BC_Cab_Pedido_Compra
 * / BC_Det_Pedido_Compra (alimentadas por un proceso externo, solo lectura para
 * este servicio) y los guarda en las tablas de negocio Pedido_Compra /
 * Detalle_Pedido_Compra. Nunca escribe nada en las tablas BC_*.
 *
 * Matching:
 *  - Empresa local <-> BC_Cab_Pedido_Compra.Empresa / BC_Det_Pedido_Compra.Empresa
 *    vía Empresa.Empresa_BC (el Nro_Pedido NO es único entre empresas, así que
 *    TODA consulta debe ir filtrada también por Empresa).
 *  - BC_Cab_Pedido_Compra.Nro_Proveedor -> BC_Ficha_Proveedor.Nro_Proveedor
 *  - BC_Ficha_Proveedor.Nro_Identificacion -> Proveedor.Ruc (local, misma empresa)
 *
 * Ventana móvil de 3 días sobre Fecha_Registro_BC. Los pedidos que ya
 * existen localmente (Abiertos o Cerrados) se excluyen por completo,
 * nunca se vuelven a tocar. Cada pedido se guarda dentro de una
 * transacción (cabecera + líneas juntas) para que nunca quede a medias.
 *
 * IMPORTANTE: sincronizar() SIEMPRE requiere $rucFiltro cuando se llama
 * desde una petición HTTP (el botón "Actualizar pedidos" de un proveedor) —
 * solo el comando programado (SincronizarPedidosDiario) puede omitirlo para
 * traer todos los proveedores de una empresa de una sola vez.
 */
class SincronizacionPedidosService
{
    public function sincronizar(int $idEmpresa, ?string $rucFiltro = null): int
    {
        $empresa = Empresa::findOrFail($idEmpresa);

        if (! $empresa->Empresa_BC) {
            throw new \RuntimeException('Esta empresa no tiene configurado el código Empresa_BC.');
        }

        $empresaBc = trim($empresa->Empresa_BC);

        $fechaDesde = now()->subDays(3)->startOfDay()->format('Y-m-d\TH:i:s');
        $fechaHasta = now()->endOfDay()->format('Y-m-d\TH:i:s');

        $nroPedidosExistentes = PedidoCompra::where('Id_Empresa', $idEmpresa)
            ->pluck('Nro_Pedido')
            ->all();

        $query = DB::table('BC_Cab_Pedido_Compra as c')
            ->join('BC_Ficha_Proveedor as p', 'p.Nro_Proveedor', '=', 'c.Nro_Proveedor')
            ->where('c.Empresa', $empresaBc)
            ->where('c.Estado_Pedido', 'Released')
            ->whereBetween('c.Fecha_Registro_BC', [$fechaDesde, $fechaHasta])
            ->select(
                'c.Nro_Pedido',
                'c.Fecha_Registro_BC',
                'c.Fecha_Recepcion_Esperada',
                'c.Estado_Pedido',
                'p.Nro_Identificacion'
            );

        if (! empty($nroPedidosExistentes)) {
            $query->whereNotIn('c.Nro_Pedido', $nroPedidosExistentes);
        }

        if ($rucFiltro) {
            $query->where('p.Nro_Identificacion', $rucFiltro);
        }

        Log::info('SQL a ejecutar', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
        ]);

        $pedidosBC = $query->get();

        Log::info('Diagnostico sync', ['total_bc' => $pedidosBC->count()]);

        $totalSincronizados = 0;

        foreach ($pedidosBC as $pedidoBC) {
            $proveedor = Proveedor::where('Id_Empresa', $idEmpresa)
                ->where('Ruc', $pedidoBC->Nro_Identificacion)
                ->first();

            if (! $proveedor) {
                continue;
            }

            DB::transaction(function () use ($pedidoBC, $idEmpresa, $empresaBc, $proveedor) {
                $pedidoLocal = PedidoCompra::create([
                    'Id_Empresa' => $idEmpresa,
                    'Id_Proveedor' => $proveedor->Id_Proveedor,
                    'Nro_Pedido' => $pedidoBC->Nro_Pedido,
                    'Fecha_Registro_BC' => $pedidoBC->Fecha_Registro_BC,
                    'Fecha_Recepcion_Esperada' => $pedidoBC->Fecha_Recepcion_Esperada,
                    'Estado_Pedido_BC' => $pedidoBC->Estado_Pedido,
                    'Estado' => 'Abierto',
                    'Fecha_Sincronizacion' => now(),
                    'Activo' => 1,
                ]);

                $lineasBC = DB::table('BC_Det_Pedido_Compra')
                    ->where('Nro_Pedido', $pedidoBC->Nro_Pedido)
                    ->where('Empresa', $empresaBc)
                    ->select('Nro_Linea', 'Nro_Producto', 'Descripcion', 'Cantidad')
                    ->get();

                foreach ($lineasBC as $linea) {
                    DetallePedidoCompra::create([
                        'Id_Pedido_Compra' => $pedidoLocal->Id_Pedido_Compra,
                        'Nro_Linea' => $linea->Nro_Linea,
                        'Codigo_Producto' => $linea->Nro_Producto,
                        'Descripcion' => $linea->Descripcion,
                        'Cantidad' => $linea->Cantidad,
                    ]);
                }
            });

            $totalSincronizados++;
        }

        return $totalSincronizados;
    }
}