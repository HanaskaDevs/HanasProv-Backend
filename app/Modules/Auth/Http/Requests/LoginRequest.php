<?php

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Services\TurnstileService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

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

            // La app Android entra sin captcha: ver 'origenes_sin_captcha'
            // en config/turnstile.php.
            if (in_array($this->header('Origin'), config('turnstile.origenes_sin_captcha', []), true)) {
                return;
            }

            if ($turnstile->esValido($this->input('cf-turnstile-response'), $this->ip())) {
                return;
            }

            // Sin token (05-nov-2026): el navegador no consiguió el captcha.
            // Antes se rechazaba sin dejar rastro y no había cómo saber a
            // quién le pasaba ni por qué (a Calidad no la dejaba entrar). El
            // frontend manda el motivo; se deja en el log con el correo y el
            // navegador, y se muestra un mensaje que dice qué hacer.
            if (blank($this->input('cf-turnstile-response'))) {
                $motivo = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $this->input('cf-turnstile-motivo', 'sin-token')) ?: 'sin-token', 0, 40);

                Log::warning('Turnstile: el navegador no envió token.', [
                    'motivo' => $motivo,
                    'email' => $this->input('email'),
                    'ip' => $this->ip(),
                    'navegador' => substr((string) $this->userAgent(), 0, 200),
                ]);

                $validator->errors()->add(
                    'email',
                    'Tu navegador no pudo cargar la verificación de seguridad (Cloudflare). Recarga la página e '
                    .'intenta de nuevo; si sigue, prueba con otro navegador o avisa a Sistemas con el código '
                    .$motivo.'.'
                );

                return;
            }

            // Mensaje en el campo del correo y no en uno técnico: es el
            // primer campo del formulario y la pantalla de login ya sabe
            // mostrar sus errores. Además no se le explica a un bot qué
            // fue exactamente lo que falló.
            //
            // Redactado como un tropiezo técnico y no como una acusación:
            // el texto anterior ("no pudimos verificar que seas una
            // persona") le salía a usuarios legítimos por fallas del propio
            // captcha, y eso ofende. Tampoco pide recargar la página: el
            // formulario pide un token nuevo en cada intento, así que
            // alcanza con volver a pulsar Ingresar.
            $validator->errors()->add(
                'email',
                'No pudimos completar la verificación de seguridad. Espera unos segundos e intenta de nuevo.'
            );
        });
    }
}
