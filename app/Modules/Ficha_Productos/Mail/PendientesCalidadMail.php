<?php

namespace App\Modules\Ficha_Productos\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El aviso a Calidad cuando Compras deja pasar uno o varios productos de un
 * proveedor (14-oct-2026).
 *
 * Trae TODO lo que ese proveedor tiene esperando a Calidad, en el orden en
 * que se califica: ficha, documentos y productos. Pedido explícito del
 * usuario: "que le llegue con todo para que califique desde la ficha del
 * proveedor, luego los documentos y luego el o los productos ... completo en
 * un solo correo". La ficha y los documentos NO tienen correo propio: este
 * es el único aviso del circuito que le llega a Calidad.
 *
 * El panorama viaja como arreglo y no como modelos: se arma en el momento
 * de la aprobación (ver AvisoProductosService::panoramaDelProveedor), así
 * el correo cuenta lo que había cuando Compras aprobó, aunque el worker lo
 * mande unos segundos después.
 *
 * Encolado por el mismo motivo que AvisoProductoMail: el envío SMTP no
 * puede frenar la aprobación de Compras.
 */
class PendientesCalidadMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array{
     *     ficha: array{estado: string, etiqueta: string, detalle: ?string},
     *     documentos: array{estado: string, etiqueta: string, detalle: ?string, pendientes: array<int, string>},
     *     productos: array{recien_aprobados: array<int, string>, otros_pendientes: array<int, string>},
     * }  $panorama
     */
    public function __construct(
        public string $asunto,
        public string $nombreProveedor,
        public ?string $rucProveedor,
        public string $quienAprobo,
        public array $panorama,
        public string $urlAccion,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pendientes-calidad',
            with: [
                'nombreProveedor' => $this->nombreProveedor,
                'rucProveedor' => $this->rucProveedor,
                'quienAprobo' => $this->quienAprobo,
                'ficha' => $this->panorama['ficha'],
                'documentos' => $this->panorama['documentos'],
                'productos' => $this->panorama['productos'],
                'urlAccion' => $this->urlAccion,
            ],
        );
    }
}
