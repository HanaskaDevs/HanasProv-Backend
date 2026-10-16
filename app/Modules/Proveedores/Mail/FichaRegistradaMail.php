<?php

namespace App\Modules\Proveedores\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a Compras: un proveedor nuevo registró su ficha y hay que
 * calificarla (16-oct-2026). Ver AvisoFichaRegistradaService para a quién
 * le llega.
 *
 * El asunto lleva fecha y hora al final por lo mismo que el de activación:
 * Gmail y Outlook agrupan en un solo hilo los correos con el mismo
 * remitente y asunto, y el segundo aviso quedaba escondido dentro del
 * primero.
 */
class FichaRegistradaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreProveedor,
        public ?string $rucProveedor,
        public ?string $nombreResponsable,
        public string $urlAccion,
    ) {
    }

    public function envelope(): Envelope
    {
        $marca = now()->format('d/m H:i');

        return new Envelope(subject: "Ficha registrada para calificar: {$this->nombreProveedor} ({$marca})");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ficha-registrada',
            with: [
                'nombreProveedor' => $this->nombreProveedor,
                'rucProveedor' => $this->rucProveedor,
                'nombreResponsable' => $this->nombreResponsable,
                'urlAccion' => $this->urlAccion,
            ],
        );
    }
}
