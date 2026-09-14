<?php

namespace App\Modules\Documentos_Proveedor\Notifications;

use App\Modules\Documentos_Proveedor\Mail\DocumentoPorVencerMail;
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
class DocumentoPorVencerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $nombreDocumento,
        protected string $nombreProveedor,
        protected string $nombreEmpresa,
        protected string $fechaCaducidad,
        protected int $diasDeAtraso,
        protected int $diasGracia,
        /**
         * Para el proveedor se fija acá (Proveedor no es un Notifiable, no
         * tiene login propio -> se manda por ruta anónima, igual que en
         * ProveedorAprobadoNotification). Para los internos va null y el
         * destinatario sale de cada $notifiable.
         */
        protected ?string $emailDestino = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): DocumentoPorVencerMail
    {
        $urlDocumentos = rtrim(config('app.frontend_url'), '/') . '/documentos';

        return (new DocumentoPorVencerMail(
            $this->nombreDocumento,
            $this->nombreProveedor,
            $this->nombreEmpresa,
            $this->fechaCaducidad,
            $this->diasDeAtraso,
            $this->diasGracia,
            $urlDocumentos,
        ))->to($this->emailDestino ?? $notifiable->Email);
    }
}
