<?php

namespace App\Modules\Ficha_Productos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_producto' => $this->Id_Producto,
            'nombre_producto' => $this->Nombre_Producto,
            'codigo_barras' => $this->Codigo_Barras,
            'unidad_presentacion' => $this->whenLoaded('unidadPresentacion', fn () => $this->unidadPresentacion?->Nombre_Unidad),
            'precio' => $this->Precio,
            'estado_calificacion' => $this->Estado_Calificacion,
            'codigo_bc' => $this->Bc_Nro_Producto,
            'proveedor' => $this->whenLoaded('proveedor', fn () => [
                'id_proveedor' => $this->proveedor->Id_Proveedor,
                'razon_social' => $this->proveedor->Razon_Social,
                'nombre_comercial' => $this->proveedor->Nombre_Comercial,
            ]),
        ];
    }
}