<?php

namespace App\Modules\Pedidos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LineaPedidoInternoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cantidadRecibida = $this->recepciones->sum('Cantidad_Recibida');

        return [
            'id_detalle_pedido_compra' => $this->Id_Detalle_Pedido_Compra,
            'nro_linea' => $this->Nro_Linea,
            'codigo_producto' => $this->Codigo_Producto,
            'descripcion' => $this->Descripcion,
            'cantidad_pedida' => $this->Cantidad,
            'cantidad_recibida' => $cantidadRecibida,
            'recepciones' => RecepcionPedidoDetalleResource::collection($this->whenLoaded('recepciones')),
        ];
    }
}