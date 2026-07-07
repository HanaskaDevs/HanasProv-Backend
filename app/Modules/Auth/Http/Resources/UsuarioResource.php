<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->Id_Usuario,
            'email' => $this->Email,
            'nombre_completo' => $this->Nombre_Completo,
            'cargo' => $this->Cargo,
            'tipo_usuario' => $this->Tipo_Usuario,
            'requiere_cambio_password' => (bool) $this->Requiere_Cambio_Password,
            'empresas' => EmpresaAccesoResource::collection($this->whenLoaded('empresas')),
        ];
    }
}
