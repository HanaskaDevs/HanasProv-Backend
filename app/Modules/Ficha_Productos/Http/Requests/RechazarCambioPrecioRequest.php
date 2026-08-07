<?php

namespace App\Modules\Ficha_Productos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RechazarCambioPrecioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'max:300'],
        ];
    }
}
