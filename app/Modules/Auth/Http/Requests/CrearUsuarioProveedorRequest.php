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
            'nombre_completo' => ['required', 'string', 'max:200'],
            'id_proveedor' => ['required', 'integer', 'exists:Proveedor,Id_Proveedor'],
        ];
    }
}
