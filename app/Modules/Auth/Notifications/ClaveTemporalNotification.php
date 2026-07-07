<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClaveTemporalNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $claveTemporal,
        protected bool $esReset = false
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $asunto = $this->esReset
            ? 'Restablecimiento de contraseña - Portal de Proveedores'
            : 'Bienvenido - Credenciales de acceso';

        return (new MailMessage)
            ->subject($asunto)
            ->greeting('Hola ' . $notifiable->Nombre_Completo . ',')
            ->line($this->esReset
                ? 'Se generó una nueva contraseña temporal para tu cuenta.'
                : 'Se creó una cuenta para ti en el Portal de Proveedores.')
            ->line('Correo: ' . $notifiable->Email)
            ->line('Contraseña temporal: ' . $this->claveTemporal)
            ->line('Por seguridad, deberás definir una nueva contraseña al iniciar sesión.')
            ->line('Esta contraseña temporal es de un solo uso y expira al cambiarla.');
    }
}