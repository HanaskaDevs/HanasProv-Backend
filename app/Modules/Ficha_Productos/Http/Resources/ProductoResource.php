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
            // El ID además del nombre: el nombre es para MOSTRAR, el id es lo
            // que necesita el <select> del formulario de edición. Sin él, la
            // pantalla tenía que buscar la unidad por nombre dentro del
            // catálogo -> depende de que el texto coincida exacto y de que el
            // catálogo ya haya cargado, y si alguien renombra "Unidad" el
            // formulario deja de encontrarla y arranca vacío.
            'id_unidad_presentacion' => $this->Id_Unidad_Presentacion,
            'precio' => $this->Precio,
            'peso' => $this->Peso,
            'volumen' => $this->Volumen,
            'unidad_por_caja' => $this->Unidad_Por_Caja,
            'precio_en_revision' => (bool) $this->Precio_En_Revision,
            'bloqueado' => (bool) $this->Bloqueado,
            'estado_calificacion' => $this->Estado_Calificacion,
            'comentario_calificacion' => $this->Comentario_Calificacion,
            // Grupos de producto (EK, CD, PH, IM...). whenLoaded para no
            // disparar una consulta por producto: el listado los trae con
            // eager loading, y quien no los cargue simplemente no los
            // recibe en vez de pagar N+1 sin darse cuenta.
            'grupos' => $this->whenLoaded('grupos', fn () => $this->grupos
                ->map(fn ($grupo) => [
                    'id_grupo_producto' => $grupo->Id_Grupo_Producto,
                    'codigo' => $grupo->Codigo,
                    'nombre' => $grupo->Nombre,
                ])->values()),
            'documentos' => $this->whenLoaded('documentos', fn() => $this->documentos
                ->where('Activo', true)
                ->map(fn($doc) => [
                    'id_documento_producto' => $doc->Id_Documento_Producto,
                    'id_tipo_documento_producto' => $doc->Id_Tipo_Documento_Producto,
                    'tipo' => $doc->tipoDocumento->Carpeta_Slug,
                    'nombre_original' => $doc->archivo->Nombre_Original,
                    'fecha_caducidad' => $doc->Fecha_Caducidad?->toDateString(),
                ])->values()),
        ];
    }
}
