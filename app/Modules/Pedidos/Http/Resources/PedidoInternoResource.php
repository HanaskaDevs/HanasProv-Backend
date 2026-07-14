<?php

namespace App\Modules\Pedidos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoInternoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_pedido_compra' => $this->Id_Pedido_Compra,
            'nro_pedido' => $this->Nro_Pedido,
            'fecha_registro_bc' => $this->Fecha_Registro_BC,
            'fecha_recepcion_esperada' => $this->Fecha_Recepcion_Esperada,
            'estado' => $this->Estado,
            'proveedor' => [
                'id_proveedor' => $this->proveedor->Id_Proveedor,
                'razon_social' => $this->proveedor->Razon_Social,
            ],
            'lineas' => LineaPedidoInternoResource::collection($this->whenLoaded('lineas')),
        ];
    }
}