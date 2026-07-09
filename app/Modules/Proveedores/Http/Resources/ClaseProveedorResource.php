<?php

namespace App\Modules\Proveedores\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaseProveedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_clase_proveedor' => $this->Id_Clase_Proveedor,
            'nombre_clase' => $this->Nombre_Clase,
        ];
    }
}