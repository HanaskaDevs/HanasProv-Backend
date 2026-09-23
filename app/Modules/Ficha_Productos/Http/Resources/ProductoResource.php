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
            // Para la bandeja de Compras, que lista productos de muchos
            // proveedores y necesita decir de quién es cada uno.
            'proveedor' => $this->whenLoaded('proveedor', fn () => [
                'id_proveedor' => $this->proveedor->Id_Proveedor,
                'razon_social' => $this->proveedor->Razon_Social,
                'nombre_comercial' => $this->proveedor->Nombre_Comercial,
            ]),
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
            // Calculado de las medidas de la unidad, no cargado a mano
            // (ver ProductoService::volumenDeLaUnidad) -> la pantalla lo
            // muestra en solo lectura.
            'volumen' => $this->Volumen,
            'volumen_masterpack' => $this->Volumen_Masterpack,
            // En pantalla es "Unidades x Masterpack (caja)"; la columna
            // conserva su nombre histórico.
            'unidad_por_caja' => $this->Unidad_Por_Caja,
            'contenido_paquete' => $this->Contenido_Paquete,
            'masterpack_largo_cm' => $this->Masterpack_Largo_Cm,
            'masterpack_ancho_cm' => $this->Masterpack_Ancho_Cm,
            'masterpack_alto_cm' => $this->Masterpack_Alto_Cm,
            'unidad_largo_cm' => $this->Unidad_Largo_Cm,
            'unidad_ancho_cm' => $this->Unidad_Ancho_Cm,
            'unidad_alto_cm' => $this->Unidad_Alto_Cm,
            'precio_en_revision' => (bool) $this->Precio_En_Revision,
            'bloqueado' => (bool) $this->Bloqueado,
            'estado_calificacion' => $this->Estado_Calificacion,
            /*
             * En qué escritorio está parado el producto: 'Compras',
             * 'Calidad' o null. Estado_Calificacion sigue siendo el
             * veredicto; esto es la etapa (ver la migración
             * 2026_09_23_090000). La pantalla lo necesita para poder
             * decirle al proveedor "lo está revisando Compras" en vez de
             * un "en revisión" que no dice a quién preguntarle.
             */
            'etapa_aprobacion' => $this->Etapa_Aprobacion,
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
