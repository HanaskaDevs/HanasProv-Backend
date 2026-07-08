<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_rol' => $this->Id_Rol,
            'nombre_rol' => $this->Nombre_Rol,
            'descripcion' => $this->Descripcion,
        ];
    }
}
