<?php

namespace App\Modules\Documentos_Proveedor\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Se suspendió al proveedor por documentación vencida. Va al proveedor
 * (para que sepa por qué dejó de entrar) y a Calidad/Admin, que son quienes
 * lo van a reactivar cuando reciba el documento -> sin este correo, el
 * proveedor llama al administrador y el administrador no sabe de qué le
 * están hablando.
 */
class ProveedorSuspendidoMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  array<int, string>  $documentosVencidos */
    public function __construct(
        public string $nombreProveedor,
        public string $nombreEmpresa,
        public array $documentosVencidos,
        public int $diasGracia,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Acceso suspendido por documentación vencida: {$this->nombreProveedor}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.proveedor-suspendido',
            with: [
                'nombreProveedor' => $this->nombreProveedor,
                'nombreEmpresa' => $this->nombreEmpresa,
                'documentosVencidos' => $this->documentosVencidos,
                'diasGracia' => $this->diasGracia,
            ],
        );
    }
}
