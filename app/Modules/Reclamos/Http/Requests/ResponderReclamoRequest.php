<?php

namespace App\Modules\Reclamos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResponderReclamoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mensaje' => ['required', 'string', 'max:2000'],
            'imagenes' => ['nullable', 'array', 'max:5'],
            'imagenes.*' => ['file', 'image', 'max:5120'],
        ];
    }
}