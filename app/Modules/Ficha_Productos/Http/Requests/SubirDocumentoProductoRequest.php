<?php

namespace App\Modules\Ficha_Productos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubirDocumentoProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

   public function rules(): array
{
    return [
        'archivo' => ['required', 'file', 'mimes:pdf', 'max:4096'],
        // La obligatoriedad real (si el tipo Requiere_Fecha_Caducidad o
        // Permite_Multiples) se valida en ProductoService::subirDocumento,
        // porque depende del Tipo_Documento_Producto puntual -> acá solo
        // se valida el formato de lo que sí venga.
        'fecha_caducidad' => ['nullable', 'date'],
        'nombre_documento' => ['nullable', 'string', 'max:150'],
    ];
}
}