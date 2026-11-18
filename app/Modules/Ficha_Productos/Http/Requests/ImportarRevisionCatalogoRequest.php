<?php

namespace App\Modules\Ficha_Productos\Http\Requests;

use App\Modules\Ficha_Productos\Services\RevisionCatalogoExcelService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filas del Excel de "Productos por revisar" que Catálogo vuelve a subir.
 *
 * El .xlsx lo lee el navegador (SheetJS) y llega acá ya convertido a JSON,
 * igual que el de códigos BC (ver ImportarCodigosBcRequest): el backend
 * nunca parsea binarios de Excel.
 *
 * Acá solo se valida la FORMA. Todas las celdas llegan como texto, tal como
 * estaban escritas, y es RevisionCatalogoExcelService quien las interpreta
 * (número, categoría, unidad, grupos) y arma el reporte fila por fila: un
 * precio mal escrito tiene que salir en ese reporte con su número de fila,
 * no tirar abajo el archivo entero con un 422.
 *
 * Todas las columnas editables son 'sometimes': la que no viene en el
 * archivo no se toca (ver el comentario del servicio).
 */
class ImportarRevisionCatalogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $reglas = [
            'filas' => ['required', 'array', 'min:1', 'max:'.RevisionCatalogoExcelService::MAX_FILAS],
            'filas.*.fila' => ['required', 'integer', 'min:1'],
            'filas.*.id_producto' => ['required', 'integer', 'min:1'],
        ];

        foreach (array_keys(RevisionCatalogoExcelService::CAMPOS) as $campo) {
            // 300 y no el largo real de cada columna: el tope de cada dato lo
            // informa el servicio en el reporte; esto solo frena basura.
            $reglas["filas.*.{$campo}"] = ['sometimes', 'nullable', 'string', 'max:300'];
        }

        return $reglas;
    }

    public function messages(): array
    {
        return [
            'filas.required' => 'El archivo no contiene filas para procesar.',
            'filas.max' => 'El archivo supera el máximo de '.RevisionCatalogoExcelService::MAX_FILAS.' filas por carga.',
            'filas.*.id_producto.required' => 'Hay filas sin la columna ID. No modifiques ni borres esa columna del Excel.',
            'filas.*.*.max' => 'Hay una celda con más de 300 caracteres.',
        ];
    }
}
