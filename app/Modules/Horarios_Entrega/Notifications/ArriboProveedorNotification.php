<?php

namespace App\Modules\Horarios_Entrega\Notifications;

use App\Modules\Horarios_Entrega\Mail\ArriboProveedorMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Avisa a cada usuario de Calidad que llegó un proveedor (12-nov-2026).
 *
 * Se manda con Notification::send() a varios usuarios internos, así que
 * el destinatario sale de cada $notifiable -mismo criterio que
 * RecepcionesDelDiaNotification-.
 */
class ArriboProveedorNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $nombreProveedor,
        protected string $horaProgramada,
        protected string $horaArribo,
        protected string $nombreQuienMarco,
        protected ?string $anden,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): ArriboProveedorMail
    {
        $urlSeguimiento = rtrim(config('app.frontend_url'), '/') . '/calendario/seguimiento';

        return (new ArriboProveedorMail(
            $this->nombreProveedor,
            $this->horaProgramada,
            $this->horaArribo,
            $this->nombreQuienMarco,
            $this->anden,
            $urlSeguimiento,
        ))->to($notifiable->Email);
    }
}
