<?php

namespace App\Console\Commands;

use App\Modules\Pedidos\Models\PedidoCompra;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CerrarPedidosVencidosCommand extends Command
{
    protected $signature = 'pedidos:cerrar-vencidos';
    protected $description = 'Cierra automáticamente los pedidos cuya fecha de recepción esperada ya pasó.';

    public function handle(): int
    {
        $hoy = now()->format('Y-m-d');
        $ahora = now()->format('Y-m-d H:i:s');

        $total = PedidoCompra::where('Estado', 'Abierto')
            ->where('Activo', 1)
            ->whereNotNull('Fecha_Recepcion_Esperada')
            ->whereRaw('CONVERT(date, Fecha_Recepcion_Esperada) < CONVERT(date, ?)', [$hoy])
            ->update([
                'Estado' => 'Cerrado',
                'Fecha_Cierre' => DB::raw("CONVERT(datetime, '{$ahora}', 120)"),
            ]);

        $this->info("Se cerraron automáticamente {$total} pedido(s) vencido(s).");

        return self::SUCCESS;
    }
}