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
            }
        );

        $this->info("Se activaron {$activados} proveedor(es) que ya cumplían las 3 condiciones.");

        return self::SUCCESS;
    }
}