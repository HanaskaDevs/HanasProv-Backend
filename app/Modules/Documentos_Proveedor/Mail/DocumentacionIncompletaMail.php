<?php

namespace App\Modules\Documentos_Proveedor\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Recordatorio al proveedor de que su documentación está incompleta, con la
 * lista de los documentos obligatorios que le faltan subir (29-oct-2026, ver
 * RecordatorioDocumentacionService).
 *
 * Encolado (ShouldQueue) como todos los correos del portal: el envío SMTP
 * no ocurre dentro del comando ni de una petición.
 */
class DocumentacionIncompletaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $faltantes  Nombres de los documentos que faltan.
     */
    public function __construct(
        public string $nombreProveedor,
        public string $nombreEmpresa,
        public array $faltantes,
        public int $diasSinNovedad,
        public string $urlDocumentos,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Documentación incompleta: le faltan '.count($this->faltantes).' documento(s)');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.documentacion-incompleta',
            with: [
                'nombreProveedor' => $this->nombreProveedor,
                'nombreEmpresa' => $this->nombreEmpresa,
                'faltantes' => $this->faltantes,
                'diasSinNovedad' => $this->diasSinNovedad,
                'urlDocumentos' => $this->urlDocumentos,
            ],
        );
    }
}
