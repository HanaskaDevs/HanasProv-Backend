<?php

namespace App\Modules\Proveedores\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoriaProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_categoria_producto' => $this->Id_Categoria_Producto,
            'nombre_categoria' => $this->Nombre_Categoria,
            'descripcion' => $this->Descripcion,
        ];
    }
}