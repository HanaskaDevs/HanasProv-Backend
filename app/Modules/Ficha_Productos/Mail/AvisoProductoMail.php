<?php

namespace App\Modules\Ficha_Productos\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Los tres avisos del circuito de aprobación de productos comparten este
 * Mailable y la vista emails.aviso-producto: lo que cambia entre ellos es
 * el texto y si llevan observación, no la estructura.
 *
 * Encolado (ShouldQueue): el envío SMTP no ocurre dentro de la petición.
 * Sin esto, aprobar un producto haría esperar al usuario los ~4 segundos
 * que tarda el servidor de correo, y como el proceso atiende una petición
 * por vez, unas pocas seguidas dejarían el portal sin atender (mismo
 * motivo que documenta SolicitudCambioPrecioMail).
 */
class AvisoProductoMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $asunto,
        public string $mensaje,
        public string $nombreProveedor,
        public string $nombreProducto,
        public ?string $observacion = null,
        public ?string $urlAccion = null,
        public string $textoAccion = 'Abrir el portal',
        public bool $esRechazo = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.aviso-producto',
            with: [
                'mensaje' => $this->mensaje,
                'nombreProveedor' => $this->nombreProveedor,
                'nombreProducto' => $this->nombreProducto,
                'observacion' => $this->observacion,
                'urlAccion' => $this->urlAccion,
                'textoAccion' => $this->textoAccion,
                'esRechazo' => $this->esRechazo,
            ],
        );
    }
}
