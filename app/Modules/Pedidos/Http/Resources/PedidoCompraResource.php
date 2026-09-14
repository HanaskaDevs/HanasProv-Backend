<?php

namespace App\Modules\Pedidos\Http\Resources;

use App\Modules\Proveedores\Services\CalificacionGlobalService;
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

        // Porcentaje del pedido POR CANTIDAD: lo recibido sobre lo pedido,
        // topando cada línea en su cantidad pedida.
        //
        // Antes esto era el promedio de los porcentajes de las líneas, que
        // le da el mismo peso a una línea de 1 unidad que a una de 1000.
        // Se cambió porque este número ES el que alimenta el fill rate de
        // la calificación global (vale el 50% de la nota, ver
        // CalificacionGlobalService): si la pantalla de Pedidos y la nota
        // usaran fórmulas distintas, el proveedor vería un porcentaje en su
        // pedido y otro en su calificación sin manera de explicar la
        // diferencia. En los datos actuales hay pedidos donde las dos
        // fórmulas difieren en 20 puntos.
        //
        // El tope por línea evita que una sobre-entrega tape el faltante de
        // otra línea del mismo pedido.
        $porcentajeEntregaPedido = 0;
        if ($lineas instanceof \Illuminate\Support\Collection && $lineas->isNotEmpty()) {
            $totalPedido = $lineas->sum(fn ($linea) => (float) $linea['cantidad']);
            $totalRecibidoTopado = $lineas->sum(
                fn ($linea) => min((float) $linea['cantidad_recibida'], (float) $linea['cantidad'])
            );

            $porcentajeEntregaPedido = $totalPedido > 0
                ? round($totalRecibidoTopado / $totalPedido * 100)
                : 0;
        }

        return [
            'id_pedido_compra' => $this->Id_Pedido_Compra,
            'nro_pedido' => $this->Nro_Pedido,
            'fecha_registro_bc' => $this->Fecha_Registro_BC?->toDateString(),
            'fecha_recepcion_esperada' => $this->Fecha_Recepcion_Esperada?->toDateString(),
            // Fecha con la que el pedido quedó clasificado en Vigentes /
            // Históricos: la esperada si vino de BC, la de registro si no.
            'fecha_recepcion_efectiva' => ($this->Fecha_Recepcion_Esperada ?? $this->Fecha_Registro_BC)?->toDateString(),
            'usa_fecha_registro_como_recepcion' => $this->Fecha_Recepcion_Esperada === null,
            'estado' => $this->Estado,
            'porcentaje_entrega' => $porcentajeEntregaPedido,
            // true = este pedido entra al promedio del fill rate de la
            // calificación global. Solo entran los cerrados: uno abierto
            // todavía se está entregando, y contarlo a medias castigaría al
            // proveedor por algo que aún no terminó. La interfaz lo usa para
            // aclarar por qué un pedido muestra porcentaje pero no pesa en
            // la nota todavía.
            'cuenta_para_calificacion' => $this->Estado === CalificacionGlobalService::ESTADO_PEDIDO_COMPUTABLE,
            'lineas' => $lineas,
        ];
    }
}