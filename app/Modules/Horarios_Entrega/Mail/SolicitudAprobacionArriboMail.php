<?php

namespace App\Modules\Horarios_Entrega\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a quien aprueba (config('portal.email_aprobacion_arribo'), en
 * pruebas iflores@hanaska.com): un horario cayó a Rechazado y el Guardia
 * pide igual registrar el arribo -> mismo patrón visual que
 * SolicitudCambioPrecioMail (plantilla Blade con marca, ver
 * resources/views/emails/solicitud-aprobacion-arribo.blade.php).
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
class SolicitudAprobacionArriboMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreProveedor,
        public string $horaProgramada,
        public string $nombreSolicitante,
        public string $fechaSolicitud,
        public string $urlRevision,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Solicitud de aprobación de arribo: {$this->nombreProveedor}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.solicitud-aprobacion-arribo',
            with: [
                'nombreProveedor' => $this->nombreProveedor,
                'horaProgramada' => $this->horaProgramada,
                'nombreSolicitante' => $this->nombreSolicitante,
                'fechaSolicitud' => $this->fechaSolicitud,
                'urlRevision' => $this->urlRevision,
            ],
        );
    }
}
