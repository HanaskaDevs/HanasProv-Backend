<?php

namespace App\Modules\Ficha_Productos\Http\Requests;

use App\Shared\FormatoPrecio;
use Illuminate\Foundation\Http\FormRequest;

class SolicitarCambioPrecioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Hasta 4 decimales (18-nov-2026). El mínimo baja con la escala:
            // con 4 decimales, el precio más chico que se puede escribir es
            // 0.0001.
            'precio_nuevo' => ['required', ...FormatoPrecio::reglas(0.0001)],
        ];
    }

    public function messages(): array
    {
        return [
            'precio_nuevo.decimal' => 'El precio admite hasta 4 decimales.',
        ];
    }
}
