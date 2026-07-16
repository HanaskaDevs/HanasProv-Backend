<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Modules\Pedidos\Models\PedidoCompra;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cada 30 minutos, actualiza Detalle_Pedido_Compra.Cantidad_Recibida con el
 * valor MÁS RECIENTE (por Fecha_Registro) de BC_Pedido_Compra_Fill_Rate para
 * cada línea, ya que esa tabla entrega la cantidad ACUMULADA total recibida
 * hasta ese momento (no es incremental, así que nunca se suma, solo se
 * reemplaza por el último valor conocido).
 *
 * Corre sobre pedidos Abiertos y Cerrados por igual (el estado del pedido no
 * filtra nada aquí), limitado a los últimos 5 días por Fecha_Registro_BC.
 */
class ActualizarCantidadesRecibidasCommand extends Command
{
    protected $signature = 'pedidos:actualizar-cantidades-recibidas';
    protected $description = 'Actualiza la cantidad recibida de las líneas de pedido desde BC_Pedido_Compra_Fill_Rate.';

    public function handle(): int
    {
        $fechaDesde = now()->subDays(5)->startOfDay()->format('Y-m-d\TH:i:s');

        $pedidos = PedidoCompra::where('Activo', 1)
            ->where('Fecha_Registro_BC', '>=', $fechaDesde)
            ->with('lineas', 'empresa')
            ->get();

        if ($pedidos->isEmpty()) {
            $this->info('No hay pedidos dentro de la ventana de 5 días para actualizar.');
            return self::SUCCESS;
        }

        $totalLineasActualizadas = 0;

        foreach ($pedidos as $pedido) {
            $empresaBc = $pedido->empresa?->Empresa_BC ? trim($pedido->empresa->Empresa_BC) : null;

            if (! $empresaBc) {
                continue;
            }

            foreach ($pedido->lineas as $linea) {
                $ultimoRegistro = DB::table('BC_Pedido_Compra_Fill_Rate')
                    ->where('Empresa', $empresaBc)
                    ->where('Nro_Documento', $pedido->Nro_Pedido)
                    ->where('Nro_Linea', $linea->Nro_Linea)
                    ->orderByDesc('Fecha_Registro')
                    ->first();

                if (! $ultimoRegistro) {
                    continue;
                }

                $linea->forceFill([
                    'Cantidad_Recibida' => $ultimoRegistro->Cantidad_Recibida ?? 0,
                ])->save();

                $totalLineasActualizadas++;
            }
        }

        Log::info('Actualización de cantidades recibidas completada', [
            'pedidos_revisados' => $pedidos->count(),
            'lineas_actualizadas' => $totalLineasActualizadas,
        ]);

        $this->info("Se actualizaron {$totalLineasActualizadas} línea(s) de pedido.");

        return self::SUCCESS;
    }
}