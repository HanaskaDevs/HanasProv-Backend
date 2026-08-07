<?php

namespace App\Modules\Proveedores\Notifications;

use App\Modules\Proveedores\Mail\ProveedorRechazadoMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProveedorRechazadoNotification extends Notification
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
