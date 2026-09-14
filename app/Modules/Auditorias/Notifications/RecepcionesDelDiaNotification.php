<?php

namespace App\Modules\Auditorias\Notifications;

use App\Modules\Auditorias\Mail\RecepcionesDelDiaMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Encolado (ShouldQueue): el envío SMTP NO ocurre dentro de la petición.
 *
 * Medido antes del cambio: /auth/olvide-password tardaba 4,36 s porque
 * esperaba al servidor de correo. Como el proceso atiende una petición por
 * vez, unas pocas llamadas seguidas dejaban el portal sin atender a nadie.
 * Encolado, la petición contesta al instante y el correo sale por el worker
 * (ver routes/console.php).
 */
class RecepcionesDelDiaNotification extends Notification implements ShouldQueue
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
