<?php

namespace App\Modules\Configuraciones\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarPoliticaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['required', 'string'],
            'orden' => ['nullable', 'integer'],
            'activo' => ['nullable', 'boolean'],
        ];
    }
}