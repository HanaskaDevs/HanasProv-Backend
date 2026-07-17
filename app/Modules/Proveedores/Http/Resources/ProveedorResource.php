<?php

namespace App\Modules\Proveedores\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProveedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->Id_Proveedor,
            'ruc' => $this->Ruc,
            'razon_social' => $this->Razon_Social,
            'nombre_comercial' => $this->Nombre_Comercial,
            'email' => $this->Email,
            'estado' => $this->whenLoaded('estado', fn () => $this->estado->Nombre_Estado),
            'porcentaje_completado_ficha' => $this->Porcentaje_Completado_Ficha,
            'fecha_postulacion' => $this->Fecha_Postulacion,

            'documentacion_registrada' => $this->Fecha_Registro_Documentacion !== null,
            'estado_calificacion_ficha' => $this->whenLoaded(
                'calificacionesCampos',
                fn () => $this->estadoGeneralCalificacionFicha()
            ),
            'documentos_totales' => $this->whenCounted('documentos_totales_count'),
            'documentos_pendientes_calificar' => $this->whenCounted('documentos_pendientes_calificar_count'),
        ];
    }
}