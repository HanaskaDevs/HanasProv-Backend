<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrearUsuarioProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
{
    return [
        'email' => ['required', 'email', 'max:150'],
        'id_empresas' => ['required', 'array', 'min:1'],
        'id_empresas.*' => ['integer', 'exists:Empresa,Id_Empresa'],
    ];
}
}
