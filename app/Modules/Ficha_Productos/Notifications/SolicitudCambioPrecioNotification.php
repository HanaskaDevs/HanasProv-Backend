<?php

namespace App\Modules\Ficha_Productos\Notifications;

use App\Modules\Ficha_Productos\Mail\SolicitudCambioPrecioMail;
use App\Modules\Ficha_Productos\Models\SolicitudCambioPrecio;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SolicitudCambioPrecioNotification extends Notification
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
