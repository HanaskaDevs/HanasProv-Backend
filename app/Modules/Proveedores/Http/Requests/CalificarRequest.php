<?php

namespace App\Modules\Proveedores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalificarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorización real (rol Admin/Sistemas) se valida en el Service.
    }

    public function rules(): array
    {
        return [
            'aprobado' => ['required', 'boolean'],
            // La observación es obligatoria SOLO al rechazar (aprobado=false):
            // es la retroalimentación que el proveedor va a ver para saber
            // qué corregir. Al aprobar, es opcional.
            'observacion' => ['nullable', 'string', 'max:1000', 'required_if:aprobado,false'],
        ];
    }

    public function messages(): array
    {
        return [
            'observacion.required_if' => 'Indica una observación para explicarle al proveedor por qué se rechaza.',
        ];
    }
}