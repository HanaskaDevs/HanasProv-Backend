<?php

namespace App\Modules\Ficha_Productos\Http\Requests;

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
            'precio_nuevo' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
