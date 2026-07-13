<?php

namespace App\Modules\Pedidos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoCompraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_pedido_compra' => $this->Id_Pedido_Compra,
            'nro_pedido' => $this->Nro_Pedido,
            'fecha_registro_bc' => $this->Fecha_Registro_BC?->toDateString(),
            'fecha_recepcion_esperada' => $this->Fecha_Recepcion_Esperada?->toDateString(),
            'estado' => $this->Estado,
            'lineas' => $this->whenLoaded('lineas', fn() => $this->lineas->map(fn($linea) => [
                'nro_linea' => $linea->Nro_Linea,
                'codigo_producto' => $linea->Codigo_Producto,
                'descripcion' => $linea->Descripcion,
                'cantidad' => $linea->Cantidad,
            ])),
        ];
    }
}
