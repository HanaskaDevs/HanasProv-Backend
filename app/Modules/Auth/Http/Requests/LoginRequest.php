<?php

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Services\TurnstileService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            /*
             * El token del captcha invisible. Nombre igual al que usa
             * Cloudflare en su propio formulario, para no traducirlo en el
             * camino.
             *
             * 'nullable' acá y no 'required': si el captcha está apagado,
             * el campo no viene y no tiene por qué fallar. Quien decide si
             * hace falta es withValidator(), más abajo.
             */
            'cf-turnstile-response' => ['nullable', 'string'],
        ];
    }

    /**
     * EL CAPTCHA SE COMPRUEBA ACÁ, EN EL REQUEST, y no en el controlador ni
     * en AuthService.
     *
     * Es a propósito: así el intento se corta ANTES de tocar la base y
     * antes de gastar el Hash::check, que es la parte cara del login (lenta
     * por diseño) y justamente lo que un bot busca agotar inundando el
     * endpoint. Validarlo más adentro dejaría pasar ese costo.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $turnstile = app(TurnstileService::class);

            if (! $turnstile->estaActivo()) {
                return;
            }

            if ($turnstile->esValido($this->input('cf-turnstile-response'), $this->ip())) {
                return;
            }

            // Mensaje en el campo del correo y no en uno técnico: es el
            // primer campo del formulario y la pantalla de login ya sabe
            // mostrar sus errores. Además no se le explica a un bot qué
            // fue exactamente lo que falló.
            $validator->errors()->add(
                'email',
                'No pudimos verificar que seas una persona. Recarga la página e intenta de nuevo.'
            );
        });
    }
}
