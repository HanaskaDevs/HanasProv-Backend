<?php

namespace App\Modules\Auditorias\Notifications;

use App\Modules\Auditorias\Mail\RecepcionesDelDiaMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RecepcionesDelDiaNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<int, array{razon_social: string, ruc: ?string, nombre_comercial: ?string}>  $proveedores
     */
    public function __construct(
        protected array $proveedores,
        protected string $nombreEmpresa,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): RecepcionesDelDiaMail
    {
        $urlPantalla = rtrim(config('app.frontend_url'), '/') . '/auditorias/recepciones';

        // Se manda con Notification::send() a varios usuarios internos, así
        // que el destinatario sale de cada $notifiable (mismo criterio que
        // SolicitudCambioPrecioNotification).
        return (new RecepcionesDelDiaMail($this->proveedores, $this->nombreEmpresa, $urlPantalla))
            ->to($notifiable->Email);
    }
}
