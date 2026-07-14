<?php

namespace App\Modules\Pedidos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarRecepcionDetalleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cantidad_recibida' => ['required', 'numeric', 'min:0'],
            'recepcion_completa' => ['required', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:500'],
            'imagenes' => ['nullable', 'array', 'max:3'],
            'imagenes.*' => ['file', 'image', 'max:5120'],
        ];
    }
}