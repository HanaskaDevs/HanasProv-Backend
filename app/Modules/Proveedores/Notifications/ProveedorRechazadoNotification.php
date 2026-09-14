<?php

namespace App\Modules\Proveedores\Notifications;

use App\Modules\Proveedores\Mail\ProveedorRechazadoMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Encolado (ShouldQueue): el envío SMTP NO ocurre dentro de la petición.
 *
 * Medido antes del cambio: /auth/olvide-password tardaba 4,36 s porque
 * esperaba al servidor de correo. Como el proceso atiende una petición por
 * vez, unas pocas llamadas seguidas dejaban el portal sin atender a nadie.
 * Encolado, la petición contesta al instante y el correo sale por el worker
 * (ver routes/console.php).
 */
class ProveedorRechazadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $emailProveedor,
        protected string $nombreProveedor,
        protected string $nombreEmpresa,
        protected array $camposRechazados,
        protected array $documentosRechazados,
        protected array $productosRechazados,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): ProveedorRechazadoMail
    {
        return (new ProveedorRechazadoMail(
            $this->nombreProveedor,
            $this->nombreEmpresa,
            $this->camposRechazados,
            $this->documentosRechazados,
            $this->productosRechazados,
        ))->to($this->emailProveedor);
    }
}
