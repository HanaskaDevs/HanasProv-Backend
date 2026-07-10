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
    ];
}
}