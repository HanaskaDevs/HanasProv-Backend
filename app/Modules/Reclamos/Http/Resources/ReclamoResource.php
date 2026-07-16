<?php

namespace App\Modules\Reclamos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReclamoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_reclamo' => $this->Id_Reclamo,
            'asunto' => $this->Asunto,
            'estado' => $this->Estado,
            'fecha_creacion' => $this->Fecha_Creacion,
            'fecha_cierre' => $this->Fecha_Cierre,
            'proveedor' => [
                'id_proveedor' => $this->proveedor->Id_Proveedor,
                'razon_social' => $this->proveedor->Razon_Social,
            ],
            'creado_por' => [
                'id_usuario' => $this->creadoPor->Id_Usuario,
                'nombre_completo' => $this->creadoPor->Nombre_Completo,
            ],
            'total_mensajes' => $this->whenLoaded('mensajes', fn() => $this->mensajes->count()),
            'destinatarios' => $this->whenLoaded('destinatarios', fn() => $this->destinatarios->map(fn($d) => [
                'rol_contacto' => $d->Rol_Contacto,
                'nombre_contacto' => $d->Nombre_Contacto,
                'email' => $d->Email,
            ])),
            'mensajes' => ReclamoMensajeResource::collection($this->whenLoaded('mensajes')),
        ];
    }
}
