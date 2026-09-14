<?php

namespace App\Modules\Proveedores\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
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
/**
 * Encolado (ShouldQueue): el envío SMTP NO ocurre dentro de la petición.
 *
 * Medido antes del cambio: /auth/olvide-password tardaba 4,36 s porque
 * esperaba al servidor de correo. Como el proceso atiende una petición por
 * vez, unas pocas llamadas seguidas dejaban el portal sin atender a nadie.
 * Encolado, la petición contesta al instante y el correo sale por el worker
 * (ver routes/console.php).
 */
class ProveedorAprobadoMail extends Mailable implements ShouldQueue
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
