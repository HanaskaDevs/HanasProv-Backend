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
            // El historial trae también las respuestas de Hana, que pueden
            // pasar de 1000 caracteres (un paso a paso, un resumen de
            // reporte). Con el mismo tope que el mensaje del usuario, la
            // primera respuesta larga hacía fallar con 422 todo mensaje
            // siguiente de esa conversación.
            'historial.*.contenido' => ['required_with:historial', 'string', 'max:8000'],
        ];
    }
}