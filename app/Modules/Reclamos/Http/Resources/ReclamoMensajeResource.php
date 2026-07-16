<?php

namespace App\Modules\Reclamos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReclamoMensajeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_reclamo_mensaje' => $this->Id_Reclamo_Mensaje,
            'mensaje' => $this->Mensaje,
            'fecha_creacion' => $this->Fecha_Creacion,
            'autor' => [
                'id_usuario' => $this->autor->Id_Usuario,
                'nombre_completo' => $this->autor->Nombre_Completo,
                'tipo_usuario' => $this->autor->Tipo_Usuario,
            ],
            'imagenes' => $this->whenLoaded('imagenes', fn () => $this->imagenes->map(fn ($img) => [
                'id_reclamo_mensaje_imagen' => $img->Id_Reclamo_Mensaje_Imagen,
                'nombre_original' => $img->archivo->Nombre_Original,
            ])),
        ];
    }
}