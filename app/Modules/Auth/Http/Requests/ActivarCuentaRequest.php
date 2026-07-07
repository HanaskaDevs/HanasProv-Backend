<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActivarCuentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'codigo' => ['required', 'string', 'max:10'],
            'password_nueva' => ['required', 'string', 'min:8', 'confirmed'],
            // Solo obligatorios en la primera activación (código tipo "Bienvenida").
            // La validación condicional real se hace en el UsuarioService,
            // porque depende de consultar el tipo de código en base de datos.
            'nombre_completo' => ['nullable', 'string', 'max:200'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:20'],
        ];
    }
}
