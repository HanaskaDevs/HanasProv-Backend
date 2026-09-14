<?php

namespace App\Modules\Legal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Solicitud de ejercicio de derechos sobre datos personales (LOPDP).
 *
 * Los campos son exactamente los que exige el procedimiento publicado en la
 * política: sin nombre, cédula y correo no se puede acreditar al titular ni
 * responderle, así que son obligatorios.
 *
 * La declaración de veracidad se valida como 'accepted' y no como boolean:
 * 'accepted' exige que venga en true. Un boolean aceptaría false y la
 * solicitud entraría sin la declaración que el propio formulario dice que se
 * está firmando.
 */
class SolicitudDerechosRequest extends FormRequest
{
    /**
     * Derechos que la LOPDP reconoce al titular. Se validan contra una lista
     * cerrada para que el correo que reciba el Delegado no traiga texto
     * libre en este campo: si mañana se agrega un derecho, se agrega acá y
     * en el selector del frontend.
     */
    public const DERECHOS = [
        'informacion',
        'acceso',
        'rectificacion',
        'eliminacion',
        'oposicion',
        'portabilidad',
        'suspension',
        'revocatoria',
        'decision_automatizada',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre_completo' => ['required', 'string', 'min:3', 'max:200'],
            'email' => ['required', 'email:rfc', 'max:150'],
            // 10 dígitos para cédula ecuatoriana, 13 si envía el RUC. No se
            // valida el dígito verificador a propósito: un titular
            // extranjero puede tener otro documento, y rechazar su solicitud
            // por el formato del número sería negarle el ejercicio de un
            // derecho.
            'cedula' => ['required', 'string', 'min:5', 'max:20'],
            'celular' => ['required', 'string', 'min:7', 'max:20'],
            'derecho' => ['required', 'string', Rule::in(self::DERECHOS)],
            'detalle' => ['required', 'string', 'min:20', 'max:3000'],
            'declaracion' => ['accepted'],
            // Token de reCAPTCHA. Opcional en las reglas porque la
            // verificación real depende de que haya claves configuradas
            // (ver SolicitudDerechosController): si no las hay, el
            // formulario sigue funcionando protegido solo por el throttle.
            'recaptcha_token' => ['nullable', 'string', 'max:4000'],
        ];
    }

    public function messages(): array
    {
        return [
            'detalle.min' => 'Cuéntanos con un poco más de detalle qué derecho quieres ejercer y por qué (al menos 20 caracteres).',
            'declaracion.accepted' => 'Necesitamos que confirmes la declaración para poder atender tu solicitud.',
            'derecho.in' => 'Selecciona uno de los derechos de la lista.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre_completo' => 'nombre y apellidos',
            'email' => 'correo electrónico',
            'cedula' => 'cédula',
            'celular' => 'número de celular',
            'derecho' => 'derecho a ejercer',
            'detalle' => 'descripción',
        ];
    }
}
