<?php

namespace App\Modules\Proveedores\Notifications;

use App\Modules\Proveedores\Mail\ProveedorAprobadoMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProveedorAprobadoNotification extends Notification
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
