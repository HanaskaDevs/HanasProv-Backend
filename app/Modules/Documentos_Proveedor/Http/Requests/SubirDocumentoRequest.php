<?php

namespace App\Modules\Documentos_Proveedor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubirDocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:4096'],
            'fecha_caducidad' => ['nullable', 'date'],
        ];
    }
}