<?php

namespace App\Modules\Proveedores\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
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
/**
 * Encolado (ShouldQueue): el envío SMTP NO ocurre dentro de la petición.
 *
 * Medido antes del cambio: /auth/olvide-password tardaba 4,36 s porque
 * esperaba al servidor de correo. Como el proceso atiende una petición por
 * vez, unas pocas llamadas seguidas dejaban el portal sin atender a nadie.
 * Encolado, la petición contesta al instante y el correo sale por el worker
 * (ver routes/console.php).
 */
class ProveedorRechazadoMail extends Mailable implements ShouldQueue
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
