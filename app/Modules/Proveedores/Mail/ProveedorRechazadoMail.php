<?php

namespace App\Modules\Proveedores\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de rechazo cuando, al cerrar la calificación de productos (el
 * último paso de la revisión), la ficha quedó rechazada, o algún
 * documento quedó rechazado, o ningún producto quedó aprobado. Detalla
 * qué campos/documentos/productos se rechazaron y por qué motivo, para
 * que el proveedor sepa exactamente qué corregir.
 *
 * $camposRechazados / $documentosRechazados / $productosRechazados:
 * arrays de ['nombre' => string, 'motivo' => ?string]
 */
class ProveedorRechazadoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreProveedor,
        public string $nombreEmpresa,
        public array $camposRechazados,
        public array $documentosRechazados,
        public array $productosRechazados,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Su postulación como proveedor de ' . $this->nombreEmpresa . ' fue rechazada',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.proveedor-rechazado',
            with: [
                'nombreProveedor' => $this->nombreProveedor,
                'nombreEmpresa' => $this->nombreEmpresa,
                'camposRechazados' => $this->camposRechazados,
                'documentosRechazados' => $this->documentosRechazados,
                'productosRechazados' => $this->productosRechazados,
            ],
        );
    }
}
