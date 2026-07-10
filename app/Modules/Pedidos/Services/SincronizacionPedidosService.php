<?php

namespace App\Modules\Pedidos\Services;

use App\Models\Empresa;
use App\Modules\Pedidos\Models\DetallePedidoCompra;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;

/**
 * Trae pedidos "Release" desde RAW_BC (Cab_Pedido_Compra / Det_Pedido_Compra,
 * solo lectura) y los guarda en las tablas locales Pedido_Compra /
 * Detalle_Pedido_Compra. Nunca escribe nada en RAW_BC.
 *
 * Matching:
 *  - Empresa local <-> Cab_Pedido_Compra.Empresa vía Empresa.Empresa_BC
 *  - Cab_Pedido_Compra.Nro_Proveedor -> RAW_BC.Proveedores.Codigo_Proveedor
 *  - RAW_BC.Proveedores.Nro_Identificacion -> Proveedor.Ruc (local, misma empresa)
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

        $query = DB::connection('sqlsrv_bc')
            ->table('Cab_Pedido_Compra as c')
            ->join('Proveedores as p', 'p.Codigo_Proveedor', '=', 'c.Nro_Proveedor')
            ->where('c.Empresa', $empresa->Empresa_BC)
            ->where('c.Estado_Pedido', 'Release')
            ->select('c.Nro_Pedido', 'c.Fecha_Registro_BC', 'c.Estado_Pedido', 'p.Nro_Identificacion');

        if ($rucFiltro) {
            $query->where('p.Nro_Identificacion', $rucFiltro);
        }

        $pedidosBC = $query->get();
        $totalSincronizados = 0;

        foreach ($pedidosBC as $pedidoBC) {
            $proveedor = Proveedor::where('Id_Empresa', $idEmpresa)
                ->where('Ruc', $pedidoBC->Nro_Identificacion)
                ->first();

            // Proveedor de BC que todavía no está registrado/aprobado en el portal: se ignora.
            if (! $proveedor) {
                continue;
            }

            $pedidoLocal = PedidoCompra::updateOrCreate(
                ['Id_Empresa' => $idEmpresa, 'Nro_Pedido' => $pedidoBC->Nro_Pedido],
                [
                    'Id_Proveedor' => $proveedor->Id_Proveedor,
                    'Fecha_Registro_BC' => $pedidoBC->Fecha_Registro_BC,
                    'Estado_Pedido_BC' => $pedidoBC->Estado_Pedido,
                    'Fecha_Sincronizacion' => now(),
                    'Activo' => 1,
                    // Nota: NO se toca 'Estado' aquí — si ya estaba Cerrado
                    // manualmente por un interno, updateOrCreate no lo pisa
                    // porque 'Estado' no está en este array de valores.
                ]
            );

            $lineasBC = DB::connection('sqlsrv_bc')
                ->table('Det_Pedido_Compra')
                ->where('Nro_Pedido', $pedidoBC->Nro_Pedido)
                ->get();

            foreach ($lineasBC as $linea) {
                DetallePedidoCompra::updateOrCreate(
                    ['Id_Pedido_Compra' => $pedidoLocal->Id_Pedido_Compra, 'Nro_Linea' => $linea->Nro_Linea],
                    [
                        'Codigo_Producto' => $linea->Codigo_Producto,
                        'Descripcion' => $linea->Descripcion,
                        'Cantidad' => $linea->Cantidad,
                        'Fecha_Recepcion_Esperada' => $linea->Fecha_Recepcion_Esperada,
                    ]
                );
            }

            $totalSincronizados++;
        }

        return $totalSincronizados;
    }
}