<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmpresaAccesoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_empresa' => $this->Id_Empresa,
            'razon_social' => $this->Razon_Social,
            'nombre_comercial' => $this->Nombre_Comercial,
            'id_rol' => $this->pivot->Id_Rol ?? null,
            'nombre_rol' => $this->pivot->rol->Nombre_Rol ?? null,
            'activo' => (bool) ($this->pivot->Activo ?? false),
        ];
    }
}
