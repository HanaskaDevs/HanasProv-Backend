<?php

namespace App\Modules\Ficha_Productos\Mail;

use App\Modules\Ficha_Productos\Models\SolicitudCambioPrecio;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a Admin/Calidad de la empresa: un proveedor YA APROBADO pidió
 * cambiar el precio de un producto. Mismo patrón visual que
 * CodigoActivacionMail (plantilla Blade con marca, no el MailMessage
 * genérico) -> ver resources/views/emails/solicitud-cambio-precio.blade.php.
 */
class SolicitudCambioPrecioMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SolicitudCambioPrecio $solicitud,
        public string $nombreProducto,
        public string $nombreProveedor,
        public string $urlRevision,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Cambio de precio pendiente de aprobación: {$this->nombreProducto}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.solicitud-cambio-precio',
            with: [
                'nombreProducto' => $this->nombreProducto,
                'nombreProveedor' => $this->nombreProveedor,
                'precioAnterior' => $this->solicitud->Precio_Anterior,
                'precioNuevo' => $this->solicitud->Precio_Nuevo,
                'urlRevision' => $this->urlRevision,
            ],
        );
    }
}
