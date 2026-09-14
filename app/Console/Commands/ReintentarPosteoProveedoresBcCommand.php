<?php

namespace App\Console\Commands;

use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\SincronizacionProveedorBcService;
use Illuminate\Console\Command;

/**
 * Reintenta registrar en Business Central los proveedores que YA están
 * aprobados en el portal pero que no llegaron a BC (BC caído, un campo
 * rechazado, la empresa sin Codigo_Company_BC, etc.).
 *
 * Hace falta un comando aparte porque
 * ReconciliarEstadosProveedoresCommand solo mira ASPIRANTES: un
 * proveedor que ya pasó a Aprobado y falló el posteo no lo vuelve a
 * tocar nadie, y quedaría invisible para siempre.
 *
 * Es seguro correrlo cuantas veces se quiera: el servicio no reprocesa a
 * los que ya tienen Nro_Proveedor_BC.
 */
class ReintentarPosteoProveedoresBcCommand extends Command
{
    protected $signature = 'proveedores:reintentar-posteo-bc {--id= : Reintentar solo un proveedor puntual}';

    protected $description = 'Reintenta registrar en Business Central los proveedores aprobados que quedaron sin postear.';

    public function handle(SincronizacionProveedorBcService $sincronizacion): int
    {
        if (! config('bc.habilitado')) {
            $this->warn('El posteo a BC está deshabilitado (BC_POSTEO_HABILITADO=false). No se hace nada.');

            return self::SUCCESS;
        }

        $query = Proveedor::where('Activo', 1)
            ->where('Id_Estado_Proveedor', EstadoProveedor::APROBADO)
            ->whereNull('Nro_Proveedor_BC')
            ->with(['clases', 'empresa']);

        if ($id = $this->option('id')) {
            $query->where('Id_Proveedor', (int) $id);
        }

        $pendientes = $query->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay proveedores aprobados pendientes de registrar en BC.');

            return self::SUCCESS;
        }

        $this->info("Pendientes: {$pendientes->count()}");

        $ok = 0;
        $fallaron = 0;

        foreach ($pendientes as $proveedor) {
            $exito = $sincronizacion->sincronizarSiCorresponde($proveedor);

            if ($exito) {
                $ok++;
                $this->line("  OK  #{$proveedor->Id_Proveedor} {$proveedor->Razon_Social} -> {$proveedor->fresh()->Nro_Proveedor_BC}");
            } else {
                $fallaron++;
                // El motivo ya quedó guardado en Error_Posteo_BC por el
                // servicio; se repite acá para no tener que ir a la base
                // a ver por qué falló.
                $this->error("  FALLO #{$proveedor->Id_Proveedor} {$proveedor->Razon_Social}: {$proveedor->fresh()->Error_Posteo_BC}");
            }
        }

        $this->info("Registrados: {$ok} · Fallaron: {$fallaron}");

        return self::SUCCESS;
    }
}
