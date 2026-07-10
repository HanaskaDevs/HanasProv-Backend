<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Modules\Pedidos\Services\SincronizacionPedidosService;
use Illuminate\Console\Command;

class SincronizarPedidosDiario extends Command
{
    protected $signature = 'pedidos:sincronizar-diario';
    protected $description = 'Sincroniza pedidos "Release" desde RAW_BC para todas las empresas con Empresa_BC configurado.';

    public function handle(SincronizacionPedidosService $servicio): int
    {
        $empresas = Empresa::where('Activo', 1)->whereNotNull('Empresa_BC')->get();

        foreach ($empresas as $empresa) {
            try {
                $total = $servicio->sincronizar($empresa->Id_Empresa);
                $this->info("Empresa {$empresa->Razon_Social}: {$total} pedidos sincronizados.");
            } catch (\Throwable $e) {
                $this->error("Empresa {$empresa->Razon_Social}: error - {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}