<?php

namespace App\Modules\Legal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Http\Requests\SolicitudDerechosRequest;
use App\Modules\Legal\Mail\SolicitudDerechosMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Recibe el formulario de atención de derechos y se lo manda al Delegado de
 * Protección de Datos.
 *
 * ES UN ENDPOINT PÚBLICO QUE ENVÍA CORREO, y eso hay que tratarlo con
 * cuidado: sin freno es un relay de spam contra la propia casilla del
 * Delegado. Tiene dos capas:
 *
 *  1. Throttle en la ruta (ver Legal/routes.php). Es la que siempre está.
 *  2. reCAPTCHA, SI hay claves configuradas. Si no las hay, el formulario
 *     sigue funcionando con el throttle solo -> se prefiere que un titular
 *     pueda ejercer su derecho aunque falte configurar reCAPTCHA, antes que
 *     dejarlo sin canal por un tema de infraestructura. La LOPDP obliga a
 *     tener el canal disponible.
 *
 * NO se guarda la solicitud en base de datos a propósito. Es una decisión,
 * no un olvido: guardar cédulas y motivos de solicitudes de derechos crea
 * una base de datos personales nueva que a su vez habría que declarar,
 * proteger y depurar. El correo al Delegado ya deja constancia y es donde el
 * procedimiento publicado dice que se gestiona.
 */
class SolicitudDerechosController extends Controller
{
    /** Etiquetas legibles de cada derecho, para el asunto y el cuerpo del correo. */
    private const ETIQUETAS = [
        'informacion' => 'Derecho a la información',
        'acceso' => 'Derecho de acceso',
        'rectificacion' => 'Rectificación o actualización',
        'eliminacion' => 'Eliminación',
        'oposicion' => 'Oposición',
        'portabilidad' => 'Portabilidad',
        'suspension' => 'Suspensión del tratamiento',
        'revocatoria' => 'Revocatoria del consentimiento',
        'decision_automatizada' => 'Revisión de una decisión automatizada',
    ];

    public function enviar(SolicitudDerechosRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $this->verificarRecaptcha($datos['recaptcha_token'] ?? null, $request->ip());

        $destinatario = config('portal.proteccion_datos.email');

        $correo = new SolicitudDerechosMail(
            nombreCompleto: $datos['nombre_completo'],
            email: $datos['email'],
            cedula: $datos['cedula'],
            celular: $datos['celular'],
            derechoEtiqueta: self::ETIQUETAS[$datos['derecho']],
            detalle: $datos['detalle'],
            fecha: now()->translatedFormat('d \d\e F \d\e Y, H:i'),
            // La IP se incluye solo como dato de trazabilidad del envío,
            // por si hay que investigar un abuso del formulario.
            ipOrigen: $request->ip(),
        );

        try {
            Mail::to($destinatario)->send($correo);
        } catch (\Throwable $e) {
            // Si el correo no sale, el titular TIENE que enterarse: si le
            // dijéramos "recibido" se quedaría esperando una respuesta que
            // nunca va a llegar, y el plazo de 15 días corre igual.
            Log::error('No se pudo enviar la solicitud de derechos LOPDP', [
                'error' => $e->getMessage(),
                'destinatario' => $destinatario,
            ]);

            return response()->json([
                'message' => 'No pudimos enviar tu solicitud en este momento. '
                    . "Por favor escríbenos directamente a {$destinatario} y la atendemos igual.",
            ], 503);
        }

        // El plazo legal de 15 días sigue estando en la política (apartado
        // 12), que es el documento donde corresponde comprometerlo. Acá se
        // saca a pedido: en el acuse de recibo sonaba a promesa de fecha.
        return response()->json([
            'message' => 'Recibimos tu solicitud. Te responderemos al correo que indicaste.',
        ]);
    }

    /**
     * Verifica el token de reCAPTCHA contra Google. Si no hay clave secreta
     * configurada, no verifica nada y deja pasar: ver el comentario de
     * clase sobre por qué es preferible a bloquear el canal.
     */
    protected function verificarRecaptcha(?string $token, ?string $ip): void
    {
        $secreto = config('services.recaptcha.secret_key');

        if (empty($secreto)) {
            return;
        }

        if (empty($token)) {
            throw ValidationException::withMessages([
                'recaptcha_token' => ['No pudimos verificar que no eres un robot. Recarga la página e inténtalo de nuevo.'],
            ]);
        }

        try {
            $respuesta = Http::asForm()->timeout(10)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secreto,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            $ok = $respuesta->successful() && $respuesta->json('success') === true;

            // reCAPTCHA v3 devuelve un score de 0 a 1. Por debajo del umbral
            // se trata como bot. El umbral es configurable porque el valor
            // correcto depende del tráfico real y hay que ajustarlo con
            // datos, no adivinarlo.
            $score = $respuesta->json('score');
            if ($ok && $score !== null) {
                $ok = (float) $score >= (float) config('services.recaptcha.score_minimo');
            }
        } catch (\Throwable $e) {
            // Google no responde: se deja pasar y queda registrado. Cortar
            // acá dejaría al titular sin poder ejercer su derecho por una
            // falla de red de un tercero.
            Log::warning('No se pudo verificar reCAPTCHA, se continúa sin verificación', ['error' => $e->getMessage()]);

            return;
        }

        if (! $ok) {
            throw ValidationException::withMessages([
                'recaptcha_token' => ['No pudimos verificar que no eres un robot. Recarga la página e inténtalo de nuevo.'],
            ]);
        }
    }
}
