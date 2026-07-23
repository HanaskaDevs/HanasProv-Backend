<?php

namespace App\Modules\Asistente\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnviarMensajeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mensaje' => ['required', 'string', 'max:1000'],
            'historial' => ['nullable', 'array', 'max:20'],
            'historial.*.rol' => ['required_with:historial', 'in:usuario,hana'],
            'historial.*.contenido' => ['required_with:historial', 'string', 'max:1000'],
        ];
    }
}