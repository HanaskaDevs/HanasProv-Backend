<?php

namespace App\Console\Commands;

use App\Modules\Documentos_Proveedor\Notifications\ProveedorSuspendidoNotification;
use App\Modules\Documentos_Proveedor\Services\VencimientoDocumentosService;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Console\Command;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Suspende a los proveedores cuya documentación lleva vencida más de 15 días
 * (ver VencimientoDocumentosService::DIAS_GRACIA_SUSPENSION). La suspensión
 * afecta al Proveedor DE ESA EMPRESA, no al usuario completo: si el mismo
 * usuario trabaja con otra empresa del grupo y ahí está al día, sigue
 * entrando a esa sin problema.
 *
 * Separado del comando de avisos a propósito: este SÍ pasa por el
 * interruptor de Configuraciones ("suspension_automatica_documentos"), así
 * se pueden dejar los avisos andando y frenar solo la suspensión.
 *
 * Nota operativa: la regla se evalúa cada día sobre el estado actual. Si
 * Admin reactiva a un proveedor y el documento sigue vencido hace más de 15
 * días, la próxima corrida lo vuelve a suspender -> primero el documento,
 * después la reactivación.
 */
class SuspenderProveedoresDocumentacionVencidaCommand extends Command
{
    protected $signature = 'documentos:suspender-vencidos {--forzar : Ignora el interruptor de Configuraciones}';

    protected $description = 'Suspende a los proveedores con documentación vencida hace más de 15 días.';

    public function __construct(protected VencimientoDocumentosService $servicio)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->servicio->suspensionAutomaticaActiva() && ! $this->option('forzar')) {
            $this->warn('La suspensión automática está APAGADA en Configuraciones. No se suspendió a nadie.');

            return self::SUCCESS;
        }

        $proveedores = $this->servicio->proveedoresParaSuspender();

        if ($proveedores->isEmpty()) {
            $this->info('No hay proveedores para suspender hoy.');

            return self::SUCCESS;
        }

        $suspendidos = 0;

        foreach ($proveedores as $proveedor) {
            $documentos = $this->servicio->nombresDocumentosVencidos($proveedor);

            $this->servicio->suspender($proveedor, $documentos);
            $this->notificar($proveedor, $documentos);

            $suspendidos++;
            $this->warn("  Suspendido: {$proveedor->Razon_Social} (".implode(', ', $documentos).')');
        }

        Log::warning('Proveedores suspendidos por documentación vencida', [
            'total' => $suspendidos,
            'proveedores' => $proveedores->pluck('Razon_Social')->all(),
        ]);

        $this->info("Se suspendieron {$suspendidos} proveedor(es).");

        return self::SUCCESS;
    }

    /** @param  array<int, string>  $documentos */
    protected function notificar(Proveedor $proveedor, array $documentos): void
    {
        $empresa = $proveedor->empresa;
        $nombreProveedor = $proveedor->Razon_Social ?: ($proveedor->Email ?? 'Proveedor');
        $nombreEmpresa = $empresa->Nombre_Comercial ?? $empresa->Razon_Social ?? 'Hanaska';

        $armar = fn (?string $email = null) => new ProveedorSuspendidoNotification(
            $nombreProveedor,
            $nombreEmpresa,
            $documentos,
            VencimientoDocumentosService::DIAS_GRACIA_SUSPENSION,
            $email,
        );

        if ($proveedor->Email) {
            (new AnonymousNotifiable())->route('mail', $proveedor->Email)->notify($armar($proveedor->Email));
        }

        $internos = $this->servicio->internosANotificar($proveedor->Id_Empresa);

        if ($internos->isNotEmpty()) {
            Notification::send($internos, $armar());
        }
    }
}
