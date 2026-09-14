<?php

namespace App\Modules\Auditorias\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alerta: el proveedor entregó, pasó el plazo y nadie registró la
 * calificación de esa recepción en el portal.
 *
 * Va a las personas configuradas en portal.alertas_recepcion_sin_calificar,
 * NO a Calidad: Calidad ya recibió el aviso de la mañana. Esta es la
 * escalación de que ese aviso no se atendió.
 *
 * Un solo correo por corrida con TODOS los casos pendientes, no uno por
 * proveedor: si un día se pasan cinco recepciones, cinco correos separados se
 * leen como cinco incidentes en vez de como el problema que son.
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
class RecepcionSinCalificarMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{proveedor: string, ruc: ?string, empresa: string, hora_entrega: string, anden: ?string}>  $casos
     */
    public function __construct(
        public array $casos,
        public string $fecha,
        public int $horasDePlazo,
    ) {
    }

    public function envelope(): Envelope
    {
        $cantidad = count($this->casos);

        $asunto = $cantidad === 1
            ? "Recepción sin calificar: {$this->casos[0]['proveedor']}"
            : "{$cantidad} recepciones entregadas sin calificar";

        return new Envelope(subject: $asunto);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.recepcion-sin-calificar',
            with: [
                'casos' => $this->casos,
                'fecha' => $this->fecha,
                'horasDePlazo' => $this->horasDePlazo,
            ],
        );
    }
}
