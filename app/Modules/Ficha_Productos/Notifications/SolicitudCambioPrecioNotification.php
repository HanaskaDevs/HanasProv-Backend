<?php

namespace App\Modules\Ficha_Productos\Notifications;

use App\Modules\Ficha_Productos\Mail\SolicitudCambioPrecioMail;
use App\Modules\Ficha_Productos\Models\SolicitudCambioPrecio;
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
class SolicitudCambioPrecioNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected SolicitudCambioPrecio $solicitud,
        protected string $nombreProducto,
        protected string $nombreProveedor,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): SolicitudCambioPrecioMail
    {
        $urlRevision = rtrim(config('app.frontend_url'), '/') . '/cambios-precio';

        return (new SolicitudCambioPrecioMail($this->solicitud, $this->nombreProducto, $this->nombreProveedor, $urlRevision))
            ->to($notifiable->Email);
    }
}
