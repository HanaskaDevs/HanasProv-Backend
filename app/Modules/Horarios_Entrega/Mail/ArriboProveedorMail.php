<?php

namespace App\Modules\Horarios_Entrega\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a Calidad de que un proveedor ACABA DE LLEGAR (12-nov-2026,
 * pedido del usuario: "cuando el guardia ya coloca que ha llegado el
 * proveedor que le llegue un correo a calidad").
 *
 * Es el gemelo "todo en orden" de SolicitudAprobacionArriboMail: aquel
 * sale cuando el proveedor llegó tarde y hay que decidir, este cuando
 * llegó dentro de hora y lo único que hace falta es que Calidad baje a
 * recibirlo. Por eso la franja de color es la de la marca y no la vino.
 *
 * Encolado (ShouldQueue) por lo mismo que el resto: el guardia marca el
 * arribo desde el Seguimiento de hoy y la pantalla tiene que responder al
 * instante, sin esperar al SMTP.
 */
class ArriboProveedorMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreProveedor,
        public string $horaProgramada,
        public string $horaArribo,
        public string $nombreQuienMarco,
        public ?string $anden,
        public string $urlSeguimiento,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Llegó {$this->nombreProveedor} ({$this->horaArribo})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.arribo-proveedor',
            with: [
                'nombreProveedor' => $this->nombreProveedor,
                'horaProgramada' => $this->horaProgramada,
                'horaArribo' => $this->horaArribo,
                'nombreQuienMarco' => $this->nombreQuienMarco,
                'anden' => $this->anden,
                'urlSeguimiento' => $this->urlSeguimiento,
            ],
        );
    }
}
