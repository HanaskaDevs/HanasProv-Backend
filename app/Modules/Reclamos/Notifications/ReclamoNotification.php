<?php

namespace App\Modules\Reclamos\Notifications;

use App\Modules\Reclamos\Models\Reclamo;
use App\Modules\Reclamos\Models\ReclamoMensaje;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReclamoNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Reclamo $reclamo,
        protected ReclamoMensaje $mensaje,
        protected bool $esMensajeInicial,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $autor = $this->mensaje->autor;

        $mail = (new MailMessage)
            ->subject(
                $this->esMensajeInicial
                    ? "Nuevo reclamo: {$this->reclamo->Asunto}"
                    : "Nueva respuesta en reclamo: {$this->reclamo->Asunto}"
            )
            ->greeting($this->esMensajeInicial ? 'Se ha registrado un nuevo reclamo' : 'Hay una nueva respuesta en el reclamo')
            ->line("Proveedor: {$this->reclamo->proveedor->Razon_Social}")
            ->line("Asunto: {$this->reclamo->Asunto}")
            ->line("De: {$autor->Nombre_Completo}")
            ->line($this->mensaje->Mensaje);

        foreach ($this->mensaje->imagenes as $imagen) {
            $ruta = \Illuminate\Support\Facades\Storage::disk('repositorio_proveedores')->path($imagen->archivo->Ruta_Almacenamiento);

            if (is_file($ruta)) {
                $mail->attach(Attachment::fromPath($ruta)
                    ->as($imagen->archivo->Nombre_Original)
                    ->withMime($imagen->archivo->Tipo_Mime));
            }
        }

        return $mail;
    }
}
