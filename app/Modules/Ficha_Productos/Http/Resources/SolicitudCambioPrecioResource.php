<?php

namespace App\Modules\Ficha_Productos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudCambioPrecioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_solicitud_cambio_precio' => $this->Id_Solicitud_Cambio_Precio,
            'precio_anterior' => $this->Precio_Anterior,
            'precio_nuevo' => $this->Precio_Nuevo,
            'estado' => $this->Estado,
            'fecha_solicitud' => $this->Fecha_Solicitud,
            'comentario_resolucion' => $this->Comentario_Resolucion,
            'producto' => $this->whenLoaded('producto', fn () => [
                'id_producto' => $this->producto->Id_Producto,
                'nombre_producto' => $this->producto->Nombre_Producto,
            ]),
            'proveedor' => $this->whenLoaded('producto', fn () => $this->producto->relationLoaded('proveedor') ? [
                'id_proveedor' => $this->producto->proveedor->Id_Proveedor,
                'razon_social' => $this->producto->proveedor->Razon_Social,
            ] : null),
            'solicitante' => $this->whenLoaded('solicitante', fn () => [
                'id_usuario' => $this->solicitante->Id_Usuario,
                'nombre_completo' => $this->solicitante->Nombre_Completo,
            ]),
        ];
    }
}
