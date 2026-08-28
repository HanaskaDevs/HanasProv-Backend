<?php

namespace App\Modules\Auth\Http\Requests;

use App\Shared\ReglaPasswordSegura;
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
            'password_nueva' => ['required', 'string', 'confirmed', ReglaPasswordSegura::regla()],
            // Solo obligatorios en la primera activación (código tipo "Bienvenida").
            // La validación condicional real se hace en el UsuarioService,
            // porque depende de consultar el tipo de código en base de datos.
            'nombre_completo' => ['nullable', 'string', 'max:200'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:20'],
            // Solo obligatorios en la primera activación de un usuario
            // Proveedor; como eso depende de consultar la base, la exigencia
            // real vive en UsuarioService (mismo criterio que los 3 de
            // arriba). Acá solo se valida el FORMATO de lo que venga.
            'ruc' => ['nullable', 'string', 'size:13'],
            'razon_social' => ['nullable', 'string', 'max:200'],
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
