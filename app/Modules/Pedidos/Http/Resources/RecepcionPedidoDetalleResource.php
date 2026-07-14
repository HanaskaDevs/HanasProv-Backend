<?php

namespace App\Modules\Pedidos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecepcionPedidoDetalleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_recepcion_pedido_detalle' => $this->Id_Recepcion_Pedido_Detalle,
            'fecha_recepcion' => $this->recepcion->Fecha_Recepcion,
            'registrado_por' => $this->recepcion->registradoPor->Nombre_Completo ?? null,
            'cantidad_recibida' => $this->Cantidad_Recibida,
            'recepcion_completa' => (bool) $this->Recepcion_Completa,
            'observacion' => $this->Observacion,
            'imagenes' => RecepcionImagenResource::collection($this->whenLoaded('imagenes')),
        ];
    }
}