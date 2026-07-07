<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CambiarPasswordInicialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string'],
            'password_nueva' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
