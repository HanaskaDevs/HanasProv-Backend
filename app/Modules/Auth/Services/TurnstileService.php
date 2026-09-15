<?php

namespace App\Modules\Auth\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifica contra Cloudflare el token que el navegador obtuvo del captcha
 * invisible del login.
 *
 * TRES RESULTADOS POSIBLES, y conviene tenerlos separados en la cabeza:
 *
 *   1. El captcha está apagado -> pasa, sin llamar a nadie.
 *   2. El token falta o Cloudflare dice que no es válido -> NO pasa.
 *   3. No se pudo preguntar (Cloudflare caído, sin salida, timeout) ->
 *      pasa igual y queda la advertencia en el log, porque un tercero
 *      caído no puede dejar sin portal a los proveedores. Se configura
 *      con turnstile.permitir_si_falla.
 *
 * El caso 2 y el 3 se parecen desde afuera pero son opuestos: uno es "el
 * cliente no hizo su parte" y el otro es "nosotros no pudimos comprobarlo".
 * Confundirlos es lo que vuelve a un captcha decorativo (si se deja pasar
 * todo) o a un portal frágil (si se bloquea todo).
 */
class TurnstileService
{
    /** Lo que Cloudflare espera recibir y devuelve; nombres de su API. */
    private const CAMPO_SECRET = 'secret';
    private const CAMPO_TOKEN = 'response';
    private const CAMPO_IP = 'remoteip';

    public function estaActivo(): bool
    {
        return (bool) config('turnstile.habilitado') && (bool) config('turnstile.secret');
    }

    /**
     * ¿Se puede dejar pasar este intento de login?
     *
     * @param  string|null  $token  El 'cf-turnstile-response' que mandó el navegador.
     * @param  string|null  $ip     IP del cliente; Cloudflare la usa como señal extra.
     */
    public function esValido(?string $token, ?string $ip = null): bool
    {
        if (! $this->estaActivo()) {
            return true;
        }

        // Token ausente = el cliente no ejecutó el captcha. Se rechaza: si
        // esto pasara, bastaría con omitir el campo para saltearse todo.
        if (blank($token)) {
            return false;
        }

        try {
            $respuesta = Http::asForm()
                ->timeout((int) config('turnstile.timeout', 4))
                ->post(config('turnstile.url_verificacion'), array_filter([
                    self::CAMPO_SECRET => config('turnstile.secret'),
                    self::CAMPO_TOKEN => $token,
                    self::CAMPO_IP => $ip,
                ]));

            if ($respuesta->failed()) {
                return $this->noSePudoVerificar('Cloudflare respondió '.$respuesta->status());
            }
        } catch (\Throwable $e) {
            // Sin red, DNS caído, timeout: no es culpa de quien intenta entrar.
            return $this->noSePudoVerificar($e->getMessage());
        }

        $datos = $respuesta->json();

        if (! ($datos['success'] ?? false)) {
            Log::info('Turnstile: token rechazado.', [
                'ip' => $ip,
                // error-codes dice POR QUÉ: token vencido, ya usado, secret
                // equivocada, hostname no autorizado... Es lo primero que
                // hay que mirar cuando "el captcha no deja entrar a nadie".
                'errores' => $datos['error-codes'] ?? [],
            ]);

            return false;
        }

        /*
         * La acción tiene que ser la del login. Cloudflare devuelve la que
         * declaró el navegador al pedir el token: sin esta comprobación, un
         * token obtenido en cualquier otra pantalla del sitio serviría para
         * autenticarse.
         *
         * Se compara solo si vino: los tokens de prueba de Cloudflare no
         * traen action, y es preferible que el entorno de pruebas funcione
         * a agregar una excepción por entorno.
         */
        $accionEsperada = config('turnstile.accion_login');
        $accionRecibida = $datos['action'] ?? null;

        if ($accionRecibida !== null && $accionRecibida !== $accionEsperada) {
            Log::warning('Turnstile: el token es de otra acción.', [
                'esperada' => $accionEsperada,
                'recibida' => $accionRecibida,
                'ip' => $ip,
            ]);

            return false;
        }

        return true;
    }

    /** Cloudflare no contestó. Decide según la configuración y deja rastro. */
    private function noSePudoVerificar(string $motivo): bool
    {
        $permitir = (bool) config('turnstile.permitir_si_falla', true);

        Log::warning('Turnstile: no se pudo verificar el token.', [
            'motivo' => $motivo,
            'se_dejo_pasar' => $permitir,
        ]);

        return $permitir;
    }
}
