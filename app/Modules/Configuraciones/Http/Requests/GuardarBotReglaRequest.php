<?php

namespace App\Modules\Configuraciones\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarBotReglaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => [$this->isMethod('post') ? 'required' : 'sometimes', 'in:Persona,Respaldo'],
            'palabra_clave' => ['nullable', 'string', 'max:50'],
            'contenido' => ['required', 'string', 'max:1000'],
            'orden' => ['nullable', 'integer'],
            'activo' => ['nullable', 'boolean'],
        ];
    }
}