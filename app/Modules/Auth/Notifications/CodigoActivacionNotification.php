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

        $ruta = $this->esReset ? '/restablecer-password' : '/activar-cuenta';

$urlActivacion = rtrim(config('app.frontend_url'), '/') . $ruta . '?' . http_build_query([
    'email' => $notifiable->Email,
    'codigo' => $this->codigo,
]);
       

        return (new MailMessage)
            ->subject($asunto)
            ->greeting('Hola ' . $notifiable->Nombre_Completo . ',')
            ->line($this->esReset
                ? 'Solicitaste restablecer tu contraseña. Usa el siguiente código o el botón para continuar.'
                : 'Se creó una cuenta para ti en el Portal de Proveedores. Usa el siguiente código o el botón para activarla.')
            ->line('Correo: ' . $notifiable->Email)
            ->line('Código de activación: ' . $this->codigo)
            ->action($this->esReset ? 'Restablecer mi contraseña' : 'Activar mi cuenta', $urlActivacion)
            ->line('Este código es válido por 20 minutos y de un solo uso.')
            ->line('Si el botón no funciona, ingresa manualmente a la pantalla de activación con tu correo y este código.');
    }
}
