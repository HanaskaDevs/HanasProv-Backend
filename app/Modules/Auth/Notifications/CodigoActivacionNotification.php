<?php

namespace App\Modules\Auth\Notifications;

use App\Modules\Auth\Mail\CodigoActivacionMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Antes esto devolvía un MailMessage (la plantilla genérica de Laravel:
 * "Regards, Portal-Proveedores" sin logo ni colores de marca) -> ahora
 * arma y devuelve un Mailable propio (ver CodigoActivacionMail) con su
 * propia plantilla Blade con el logo, los colores de Hanaska y un saludo
 * genérico ("Hola,") en vez de mostrar el correo del usuario como si
 * fuera su nombre (a esta altura Nombre_Completo todavía es igual al
 * Email, recién se completa cuando el usuario activa su cuenta).
 */
/**
 * Encolado (ShouldQueue): el envío SMTP NO ocurre dentro de la petición.
 *
 * Medido antes del cambio: /auth/olvide-password tardaba 4,36 s porque
 * esperaba al servidor de correo. Como el proceso atiende una petición por
 * vez, unas pocas llamadas seguidas dejaban el portal sin atender a nadie.
 * Encolado, la petición contesta al instante y el correo sale por el worker
 * (ver routes/console.php).
 */
class CodigoActivacionNotification extends Notification implements ShouldQueue
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

    public function toMail(object $notifiable): CodigoActivacionMail
    {
        $ruta = $this->esReset ? '/restablecer-password' : '/activar-cuenta';

        $urlActivacion = rtrim(config('app.frontend_url'), '/') . $ruta . '?' . http_build_query([
            'email' => $notifiable->Email,
            'codigo' => $this->codigo,
        ]);

        return (new CodigoActivacionMail($this->codigo, $urlActivacion, $this->esReset))
            ->to($notifiable->Email);
    }
}