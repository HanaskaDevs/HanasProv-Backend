<?php

namespace App\Modules\Auth\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
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
/**
 * Encolado (ShouldQueue): el envío SMTP NO ocurre dentro de la petición.
 *
 * Medido antes del cambio: /auth/olvide-password tardaba 4,36 s porque
 * esperaba al servidor de correo. Como el proceso atiende una petición por
 * vez, unas pocas llamadas seguidas dejaban el portal sin atender a nadie.
 * Encolado, la petición contesta al instante y el correo sale por el worker
 * (ver routes/console.php).
 */
class CodigoActivacionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $codigo,
        public string $urlActivacion,
        public bool $esReset = false,
    ) {
    }

    /**
     * El asunto lleva la fecha y hora al final, y no es un adorno.
     *
     * Gmail (y Outlook) agrupan en UNA sola conversación los mensajes que
     * comparten remitente y asunto. Como este correo tenía siempre el mismo
     * texto, el segundo código que se le enviaba a una persona no aparecía
     * como un correo nuevo: quedaba escondido dentro del hilo del primero,
     * que además ya estaba leído. El usuario reenviaba el código, no veía
     * nada nuevo en la bandeja y concluía que el correo no había llegado.
     *
     * Con la marca de tiempo cada envío es un asunto distinto, así que
     * siempre entra como un mensaje separado y visible. Se usa la hora y no
     * el código a propósito: el código da acceso a definir la contraseña y
     * los asuntos quedan registrados en más lugares que el cuerpo (avisos
     * del teléfono, listados, registros del servidor de correo).
     */
    public function envelope(): Envelope
    {
        $marca = now()->format('d/m H:i');

        return new Envelope(
            subject: $this->esReset
                ? "Restablecimiento de contraseña - Portal de Proveedores Hanaska ({$marca})"
                : "Bienvenido al Portal de Proveedores Hanaska ({$marca})",
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