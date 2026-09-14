<?php

namespace App\Modules\Documentos_Proveedor\Notifications;

use App\Modules\Documentos_Proveedor\Mail\ProveedorSuspendidoMail;
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
class ProveedorSuspendidoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, string>  $documentosVencidos */
    public function __construct(
        protected string $nombreProveedor,
        protected string $nombreEmpresa,
        protected array $documentosVencidos,
        protected int $diasGracia,
        protected ?string $emailDestino = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): ProveedorSuspendidoMail
    {
        return (new ProveedorSuspendidoMail(
            $this->nombreProveedor,
            $this->nombreEmpresa,
            $this->documentosVencidos,
            $this->diasGracia,
        ))->to($this->emailDestino ?? $notifiable->Email);
    }
}
