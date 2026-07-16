<?php

namespace App\Modules\Reclamos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrearReclamoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proveedor' => ['required', 'integer'],
            'asunto' => ['required', 'string', 'max:200'],
            'mensaje' => ['required', 'string', 'max:2000'],
            'destinatarios' => ['required', 'array', 'min:1'],
            'destinatarios.*.rol_contacto' => ['required', 'string', 'max:50'],
            'destinatarios.*.nombre_contacto' => ['nullable', 'string', 'max:200'],
            'destinatarios.*.email' => ['required', 'email', 'max:150'],
            'imagenes' => ['nullable', 'array', 'max:5'],
            'imagenes.*' => ['file', 'image', 'max:5120'],
        ];
    }
}