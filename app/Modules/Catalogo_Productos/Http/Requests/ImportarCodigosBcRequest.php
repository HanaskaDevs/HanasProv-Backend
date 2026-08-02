<?php

namespace App\Modules\Catalogo_Productos\Http\Requests;

use App\Modules\Catalogo_Productos\Services\CatalogoProductoService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El .xlsx lo lee el navegador (SheetJS) y llega acá ya convertido a
 * JSON -> el backend nunca parsea binarios de Excel, solo valida datos.
 * Igual todo se vuelve a verificar contra la base en el Service: que el
 * producto sea de la empresa activa y que el código exista en BC.
 */
class ImportarCodigosBcRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filas' => ['required', 'array', 'min:1', 'max:' . CatalogoProductoService::MAX_FILAS_IMPORTACION],
            'filas.*.fila' => ['required', 'integer', 'min:1'],
            'filas.*.id_producto' => ['required', 'integer', 'min:1'],
            // nullable: celda vacía es válida y significa "no tocar esta fila".
            'filas.*.bc_nro_producto' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'filas.required' => 'El archivo no contiene filas para procesar.',
            'filas.max' => 'El archivo supera el máximo de ' . CatalogoProductoService::MAX_FILAS_IMPORTACION . ' filas por carga.',
            'filas.*.id_producto.required' => 'Hay filas sin la columna ID de producto. No modifiques ni borres esa columna del Excel.',
            'filas.*.bc_nro_producto.max' => 'El código BC no puede superar los 50 caracteres.',
        ];
    }
}