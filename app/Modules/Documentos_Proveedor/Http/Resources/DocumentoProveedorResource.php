<?php

namespace App\Modules\Documentos_Proveedor\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentoProveedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_documento_proveedor' => $this->Id_Documento_Proveedor,
            'id_tipo_documento' => $this->Id_Tipo_Documento,
            'tipo_documento' => $this->whenLoaded('tipoDocumento', fn () => $this->tipoDocumento->Nombre_Documento),
            'nombre_original' => $this->whenLoaded('archivo', fn () => $this->archivo->Nombre_Original),
            'fecha_caducidad' => $this->Fecha_Caducidad?->toDateString(),
            'estado' => $this->Estado,
            'fecha_creacion' => $this->Fecha_Creacion,
        ];
    }
}