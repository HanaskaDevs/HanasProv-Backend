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
            // Los mismos formatos que exige el formulario. Se repiten acá a
            // propósito: la pantalla evita el error, pero la API es pública y
            // no puede confiar en que quien llama sea el formulario.
            // \p{L} (con /u) cubre las tildes y la ñ; \w no las cubriría.
            'nombre_completo' => ['nullable', 'string', 'min:3', 'max:200', 'regex:/^[\p{L}\s\'-]+$/u'],
            'cargo' => ['nullable', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\s\'-]+$/u'],
            'telefono' => ['nullable', 'string', 'regex:/^[0-9]{7,15}$/'],
            // Solo obligatorios en la primera activación de un usuario
            // Proveedor; como eso depende de consultar la base, la exigencia
            // real vive en UsuarioService (mismo criterio que los 3 de
            // arriba). Acá solo se valida el FORMATO de lo que venga.
            // El 'size:13' solo mide el largo: sin el regex, un RUC de 13
            // LETRAS pasaba la validación y se guardaba en la ficha.
            'ruc' => ['nullable', 'string', 'size:13', 'regex:/^\d{13}$/'],
            // La razón social SÍ admite números y signos, porque los nombres
            // legales los usan de verdad ("Comercial 2000 S.A.", "AGRO & MAR
            // CÍA. LTDA."). Lo que no admite es '<' ni '>': sin esta regla
            // entraba tal cual un "<script>alert(1)</script>".
            'razon_social' => ['nullable', 'string', 'min:3', 'max:200', 'regex:/^[\p{L}\p{N}\s.,&\/()\'-]+$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre_completo.regex' => 'El nombre completo solo puede tener letras.',
            'cargo.regex' => 'El cargo solo puede tener letras.',
            'telefono.regex' => 'El teléfono debe tener entre 7 y 15 dígitos, sin letras ni signos.',
            'ruc.size' => 'El RUC debe tener exactamente 13 dígitos.',
            'ruc.regex' => 'El RUC solo puede tener números.',
            'razon_social.regex' => 'La razón social solo puede tener letras, números y los signos . , & / ( ) - \'',
            'password_nueva.min' => ReglaPasswordSegura::descripcion(),
            'password_nueva.numbers' => ReglaPasswordSegura::descripcion(),
            'password_nueva.symbols' => ReglaPasswordSegura::descripcion(),
        ];
    }
}
