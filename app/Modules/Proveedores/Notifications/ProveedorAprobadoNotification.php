<?php

namespace App\Modules\Proveedores\Notifications;

use App\Modules\Proveedores\Mail\ProveedorAprobadoMail;
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
class ProveedorAprobadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $emailProveedor,
        protected string $nombreProveedor,
        protected string $nombreEmpresa,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): ProveedorAprobadoMail
    {
        // Proveedor no es un Notifiable (no tiene cuenta de login propia
        // como Usuario) -> se envía siempre por ruta anónima, así que acá
        // se fija el destinatario explícito en vez de derivarlo de
        // $notifiable (ver Proveedores/Services/CalificacionProveedorService).
        return (new ProveedorAprobadoMail($this->nombreProveedor, $this->nombreEmpresa))
            ->to($this->emailProveedor);
    }
}
