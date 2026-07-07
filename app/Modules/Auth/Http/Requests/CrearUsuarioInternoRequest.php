<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrearUsuarioInternoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:150'],
            'nombre_completo' => ['required', 'string', 'max:200'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'id_empresa' => ['required', 'integer', 'exists:Empresa,Id_Empresa'],
            'id_rol' => ['required', 'integer', 'exists:Rol,Id_Rol'],
        ];
    }
}
