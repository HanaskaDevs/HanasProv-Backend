<?php

namespace App\Modules\Legal\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Solicitud de ejercicio de derechos que llega al Delegado de Protección de
 * Datos.
 *
 * El replyTo apunta al correo que declaró el titular: la LOPDP da 15 días
 * para responder, y quien atienda la solicitud tiene que poder contestar
 * apretando "Responder" sin copiar direcciones a mano. El From, en cambio,
 * queda en el del sistema -> poner el correo del titular como remitente
 * haría que el servidor de correo lo trate como suplantación y termine en
 * spam.
 */
class SolicitudDerechosMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreCompleto,
        public string $email,
        public string $cedula,
        public string $celular,
        public string $derechoEtiqueta,
        public string $detalle,
        public string $fecha,
        public ?string $ipOrigen,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Solicitud de derechos LOPDP: {$this->derechoEtiqueta} — {$this->nombreCompleto}",
            replyTo: [new Address($this->email, $this->nombreCompleto)],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.solicitud-derechos',
            with: [
                'nombreCompleto' => $this->nombreCompleto,
                'email' => $this->email,
                'cedula' => $this->cedula,
                'celular' => $this->celular,
                'derechoEtiqueta' => $this->derechoEtiqueta,
                'detalle' => $this->detalle,
                'fecha' => $this->fecha,
                'ipOrigen' => $this->ipOrigen,
            ],
        );
    }
}
