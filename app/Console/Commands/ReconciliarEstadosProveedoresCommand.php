<?php

namespace App\Console\Commands;

use App\Modules\Proveedores\Services\CalificacionProveedorService;
use Illuminate\Console\Command;

class ReconciliarEstadosProveedoresCommand extends Command
{
    protected $signature = 'proveedores:reconciliar-estados';

    protected $description = 'Revisa a todos los proveedores Aspirante y activa a los que ya cumplen '
        . 'las 3 condiciones (ficha, documentación y al menos un producto Aprobados), '
        . 'por si alguno quedó atascado porque el chequeo automático no se disparó en el momento justo.';

    public function __construct(protected CalificacionProveedorService $calificacionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $activados = $this->calificacionService->reconciliarEstadosAspirantes(
            function ($proveedor, array $diagnostico) {
                $this->line("Proveedor #{$proveedor->Id_Proveedor} ({$proveedor->Ruc}):");
                $this->line('  Ficha aprobada: ' . ($diagnostico['ficha_aprobada'] ? 'sí' : 'NO'));
                $this->line(
                    '  Documentación aprobada: '
                    . ($diagnostico['documentacion_aprobada'] ? 'sí' : 'NO')
                    . " ({$diagnostico['total_documentos']} documentos activos, "
                    . "{$diagnostico['documentos_no_aprobados']} sin aprobar)"
                );
                $this->line('  Tiene producto aprobado: ' . ($diagnostico['hay_producto_aprobado'] ? 'sí' : 'NO'));

                // Un proveedor de puros servicios no necesita productos
                // -> sin esta línea, ver "producto aprobado: NO" en uno
                // que igual se activó parecía un bug del comando.
                if (! $diagnostico['requiere_productos']) {
                    $this->line('  (proveedor de solo servicios: no se le exigen productos)');
                }
            }
        );

        $this->info("Se activaron {$activados} proveedor(es) que ya cumplían las condiciones.");

        return self::SUCCESS;
    }
}