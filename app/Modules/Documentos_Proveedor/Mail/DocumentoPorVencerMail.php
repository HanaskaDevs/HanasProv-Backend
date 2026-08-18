<?php

namespace App\Modules\Documentos_Proveedor\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de documento por vencer / vencido. El mismo correo va al proveedor
 * y a los internos (Calidad y Admin); solo cambia el destinatario, no el
 * contenido, así todos ven exactamente la misma información y el mismo
 * plazo.
 */
class DocumentoPorVencerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreDocumento,
        public string $nombreProveedor,
        public string $nombreEmpresa,
        public string $fechaCaducidad,
        /** Negativo = faltan N días; positivo = vencido hace N días. */
        public int $diasDeAtraso,
        public int $diasGracia,
        public string $urlDocumentos,
    ) {
    }

    public function envelope(): Envelope
    {
        $asunto = $this->diasDeAtraso > 0
            ? "Documento VENCIDO: {$this->nombreDocumento} ({$this->nombreProveedor})"
            : 'Documento por vencer: ' . $this->nombreDocumento . ' (' . abs($this->diasDeAtraso) . ' días)';

        return new Envelope(subject: $asunto);
    }

    public function content(): Content
    {
        $vencido = $this->diasDeAtraso > 0;
        // Días que le quedan antes de la suspensión, una vez vencido.
        $diasParaSuspension = max($this->diasGracia - $this->diasDeAtraso, 0);

        return new Content(
            view: 'emails.documento-por-vencer',
            with: [
                'nombreDocumento' => $this->nombreDocumento,
                'nombreProveedor' => $this->nombreProveedor,
                'nombreEmpresa' => $this->nombreEmpresa,
                'fechaCaducidad' => $this->fechaCaducidad,
                'vencido' => $vencido,
                'diasRestantes' => abs($this->diasDeAtraso),
                'diasParaSuspension' => $diasParaSuspension,
                'diasGracia' => $this->diasGracia,
                'urlDocumentos' => $this->urlDocumentos,
            ],
        );
    }
}
