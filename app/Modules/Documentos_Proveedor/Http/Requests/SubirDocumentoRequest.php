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
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'fecha_caducidad' => ['nullable', 'date'],
        ];
    }
}