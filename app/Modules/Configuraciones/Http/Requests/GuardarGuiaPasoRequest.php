<?php

namespace App\Modules\Configuraciones\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarGuiaPasoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_id' => ['required', 'in:tour-mi-ficha,tour-documentacion,tour-productos'],
            'titulo' => ['required', 'string', 'max:100'],
            'texto' => ['required', 'string', 'max:500'],
            'orden' => ['nullable', 'integer'],
            'activo' => ['nullable', 'boolean'],
        ];
    }
}