<?php

namespace App\Modules\Proveedores\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de felicitación cuando un proveedor queda Aprobado (ficha,
 * documentación y al menos un producto ya calificados favorablemente).
 * Mismo patrón visual de marca que CodigoActivacionMail -> ver
 * resources/views/emails/proveedor-aprobado.blade.php.
 */
class ProveedorAprobadoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreProveedor,
        public string $nombreEmpresa,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '¡Felicidades! Ya es proveedor aprobado de ' . $this->nombreEmpresa,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.proveedor-aprobado',
            with: [
                'nombreProveedor' => $this->nombreProveedor,
                'nombreEmpresa' => $this->nombreEmpresa,
            ],
        );
    }
}
