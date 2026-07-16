<?php

namespace App\Modules\Pedidos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoCompraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lineas = $this->whenLoaded('lineas', fn() => $this->lineas->map(function ($linea) {
            $cantidadRecibida = (float) $linea->Cantidad_Recibida;
            $cantidadPedida = (float) $linea->Cantidad;
            $porcentajeLinea = $cantidadPedida > 0 ? round(min(100, ($cantidadRecibida / $cantidadPedida) * 100)) : 0;

            return [
                'nro_linea' => $linea->Nro_Linea,
                'codigo_producto' => $linea->Codigo_Producto,
                'descripcion' => $linea->Descripcion,
                'cantidad' => $linea->Cantidad,
                'cantidad_recibida' => $cantidadRecibida,
                'porcentaje_entrega' => $porcentajeLinea,
            ];
        }));

        $porcentajeEntregaPedido = 0;
        if ($lineas instanceof \Illuminate\Support\Collection && $lineas->isNotEmpty()) {
            $porcentajeEntregaPedido = round($lineas->avg('porcentaje_entrega'));
        }

        return [
            'id_pedido_compra' => $this->Id_Pedido_Compra,
            'nro_pedido' => $this->Nro_Pedido,
            'fecha_registro_bc' => $this->Fecha_Registro_BC?->toDateString(),
            'fecha_recepcion_esperada' => $this->Fecha_Recepcion_Esperada?->toDateString(),
            'estado' => $this->Estado,
            'porcentaje_entrega' => $porcentajeEntregaPedido,
            'lineas' => $lineas,
        ];
    }
}
