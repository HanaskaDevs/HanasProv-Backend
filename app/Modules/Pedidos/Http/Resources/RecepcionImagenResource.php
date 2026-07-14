<?php

namespace App\Modules\Pedidos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecepcionImagenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_recepcion_imagen' => $this->Id_Recepcion_Imagen,
            'nombre_original' => $this->archivo->Nombre_Original,
        ];
    }
}