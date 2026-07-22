<?php

namespace App\Modules\Proveedores\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalificarFichaGeneralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorización real (rol Admin/Sistemas) se valida en el Service.
    }

    public function rules(): array
    {
        return [
            'aprobado' => ['required', 'boolean'],
            // Al rechazar, tiene que venir al menos un campo marcado como
            // inválido, cada uno con su propia observación -> si no hay
            // ningún campo señalado, no tiene sentido "rechazar" la ficha.
            'campos_rechazados' => ['required_if:aprobado,false', 'array', 'min:1'],
            'campos_rechazados.*.campo' => ['required', 'string'],
            'campos_rechazados.*.observacion' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'campos_rechazados.required_if' => 'Selecciona al menos un campo con información inválida.',
            'campos_rechazados.min' => 'Selecciona al menos un campo con información inválida.',
            'campos_rechazados.*.observacion.required' => 'Escribe una observación para cada campo que marques.',
        ];
    }
}