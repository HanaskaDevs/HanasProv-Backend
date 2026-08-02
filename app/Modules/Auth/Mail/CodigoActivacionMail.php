<?php

namespace App\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reemplaza al MailMessage por defecto de Laravel (el que arma un correo
 * genérico con el logo "Portal-Proveedores" como texto y sin ningún
 * color de marca) -> esta plantilla propia (ver
 * resources/views/emails/codigo-activacion.blade.php) usa tablas con
 * estilos en línea a propósito, no Tailwind/flex, porque Outlook y
 * varios clientes de correo corporativos ignoran CSS moderno.
 */
class CodigoActivacionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $codigo,
        public string $urlActivacion,
        public bool $esReset = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->esReset
                ? 'Restablecimiento de contraseña - Portal de Proveedores Hanaska'
                : 'Bienvenido al Portal de Proveedores Hanaska',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.codigo-activacion',
            with: [
                'codigo' => $this->codigo,
                'urlActivacion' => $this->urlActivacion,
                'esReset' => $this->esReset,
            ],
        );
    }
}