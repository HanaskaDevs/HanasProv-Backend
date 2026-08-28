<?php

namespace App\Modules\Auth\Http\Requests;

use App\Shared\ReglaPasswordSegura;
use Illuminate\Foundation\Http\FormRequest;

class CambiarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string'],
            'password_nueva' => ['required', 'string', 'confirmed', ReglaPasswordSegura::regla()],
        ];
    }

    public function messages(): array
    {
        return [
            'password_nueva.min' => ReglaPasswordSegura::descripcion(),
            'password_nueva.numbers' => ReglaPasswordSegura::descripcion(),
            'password_nueva.symbols' => ReglaPasswordSegura::descripcion(),
        ];
    }
}
