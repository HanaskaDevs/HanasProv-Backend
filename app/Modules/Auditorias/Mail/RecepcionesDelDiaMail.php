<?php

namespace App\Modules\Auditorias\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso diario al equipo de Calidad: a estos proveedores les toca hoy su
 * auditoría de recepción (ver AgendaRecepcionService para cómo se reparten
 * en el año). Un solo correo con la lista completa, no uno por proveedor.
 *
 * Mismo patrón visual que el resto (plantilla Blade con la marca, no el
 * MailMessage genérico) -> resources/views/emails/recepciones-del-dia.blade.php.
 */
class RecepcionesDelDiaMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{razon_social: string, ruc: ?string, nombre_comercial: ?string}>  $proveedores
     */
    public function __construct(
        public array $proveedores,
        public string $nombreEmpresa,
        public string $urlPantalla,
    ) {
    }

    public function envelope(): Envelope
    {
        $total = count($this->proveedores);

        return new Envelope(
            subject: $total === 1
                ? 'Auditoría de recepción de hoy: 1 proveedor'
                : "Auditorías de recepción de hoy: {$total} proveedores",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.recepciones-del-dia',
            with: [
                'proveedores' => $this->proveedores,
                'nombreEmpresa' => $this->nombreEmpresa,
                'urlPantalla' => $this->urlPantalla,
                'fechaHoy' => now()->translatedFormat('l d \d\e F \d\e Y'),
            ],
        );
    }
}
