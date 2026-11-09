<?php

namespace App\Modules\Horarios_Entrega\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica a Business Central por token compartido (ver
 * config/integraciones.php). No hay sesión ni usuario Sanctum: el usuario
 * en cuyo nombre se trabaja lo resuelve el controlador desde la
 * configuración, nunca desde la petición.
 *
 * hash_equals y no ===: la comparación tarda lo mismo acierte o no, así el
 * tiempo de respuesta no sirve para adivinar el token letra por letra.
 */
class TokenIntegracionBc
{
    public const CABECERA = 'X-Integracion-Token';

    public function handle(Request $request, Closure $next): Response
    {
        $esperado = (string) config('integraciones.bc.token');

        if ($esperado === '') {
            return response()->json([
                'message' => 'La integración con Business Central no está habilitada en el portal (falta INTEGRACION_BC_TOKEN).',
            ], 503);
        }

        $recibido = (string) $request->header(self::CABECERA, '');

        if ($recibido === '' || ! hash_equals($esperado, $recibido)) {
            // Warning y no info: con LOG_LEVEL=warning en producción es lo
            // único que queda si BC empieza a recibir 401.
            Log::warning('Integración BC: token inválido o ausente.', [
                'ip' => $request->ip(),
                'ruta' => $request->path(),
            ]);

            return response()->json(['message' => 'Token de integración inválido.'], 401);
        }

        return $next($request);
    }
}
