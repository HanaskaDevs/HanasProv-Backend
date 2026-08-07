<?php

namespace App\Modules\Ficha_Productos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_producto' => $this->Id_Producto,
            'nombre_producto' => $this->Nombre_Producto,
            'codigo_barras' => $this->Codigo_Barras,
            'unidad_presentacion' => $this->whenLoaded('unidadPresentacion', fn() => $this->unidadPresentacion->Nombre_Unidad),
            'precio' => $this->Precio,
            'peso' => $this->Peso,
            'volumen' => $this->Volumen,
            'unidad_por_caja' => $this->Unidad_Por_Caja,
            'precio_en_revision' => (bool) $this->Precio_En_Revision,
            'bloqueado' => (bool) $this->Bloqueado,
            'estado_calificacion' => $this->Estado_Calificacion,
            'comentario_calificacion' => $this->Comentario_Calificacion,
            'documentos' => $this->whenLoaded('documentos', fn() => $this->documentos
                ->where('Activo', true)
                ->map(fn($doc) => [
                    'id_documento_producto' => $doc->Id_Documento_Producto,
                    'tipo' => $doc->tipoDocumento->Carpeta_Slug,
                    'nombre_original' => $doc->archivo->Nombre_Original,
                ])->values()),
        ];
    }
}
