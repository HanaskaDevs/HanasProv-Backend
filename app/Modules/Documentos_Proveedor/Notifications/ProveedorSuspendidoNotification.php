<?php

namespace App\Modules\Documentos_Proveedor\Notifications;

use App\Modules\Documentos_Proveedor\Mail\ProveedorSuspendidoMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProveedorSuspendidoNotification extends Notification
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
