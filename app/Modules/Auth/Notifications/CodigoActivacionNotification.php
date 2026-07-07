<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CodigoActivacionNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $codigo,
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
            : 'Bienvenido - Activa tu cuenta';

        return (new MailMessage)
            ->subject($asunto)
            ->greeting('Hola ' . $notifiable->Nombre_Completo . ',')
            ->line($this->esReset
                ? 'Solicitaste restablecer tu contraseña. Usa el siguiente código para definir una nueva.'
                : 'Se creó una cuenta para ti en el Portal de Proveedores. Usa el siguiente código para activarla.')
            ->line('Correo: ' . $notifiable->Email)
            ->line('Código de activación: ' . $this->codigo)
            ->line('Este código es válido por 20 minutos y de un solo uso.')
            ->line('Ingresa a la pantalla de activación con tu correo, este código, y la contraseña que quieras usar.');
    }
}
