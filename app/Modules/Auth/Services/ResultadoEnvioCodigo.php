<?php

namespace App\Modules\Auth\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * El resultado de mandar un código de activación, dicho en castellano y
 * no en jerga de servidores.
 *
 * POR QUÉ EXISTE. Antes, el portal contestaba siempre "Código de
 * activación reenviado correctamente", porque el correo se encolaba y la
 * petición terminaba sin esperar al servidor. Si el envío fallaba después
 * -una dirección mal escrita, el servidor pidiéndonos esperar- nadie se
 * enteraba: el aviso quedaba en failed_jobs y el usuario seguía sin su
 * código, convencido de que se lo habían mandado.
 *
 * Acá el envío se hace esperando la respuesta y el error se traduce a algo
 * que una persona de Compras pueda leer y, sobre todo, ACCIONAR: cuál es
 * el problema y qué hacer al respecto. El texto técnico se conserva
 * aparte, para el log y para que Sistemas lo pida si hace falta.
 */
class ResultadoEnvioCodigo
{
    private function __construct(
        public readonly bool $enviado,
        public readonly string $correo,
        public readonly string $titulo,
        public readonly string $mensaje,
        public readonly ?string $sugerencia,
        public readonly ?string $detalleTecnico,
    ) {}

    public static function exitoso(string $correo, int $minutosVigencia): self
    {
        return new self(
            enviado: true,
            correo: $correo,
            titulo: 'Código de activación enviado',
            mensaje: "El código de activación se envió correctamente a {$correo}.",
            sugerencia: 'Pídale que revise su bandeja de entrada y también la carpeta de correo '
                .'no deseado. El código vence en '.self::vigenciaEnPalabras($minutosVigencia).'.',
            detalleTecnico: null,
        );
    }

    public static function fallido(string $correo, Throwable $error): self
    {
        // Queda en el log con el texto completo: el modal muestra la
        // versión corta y alguien tiene que poder ver la larga después.
        Log::error('No se pudo enviar el código de activación.', [
            'correo' => $correo,
            'error' => $error->getMessage(),
        ]);

        [$mensaje, $sugerencia] = self::traducir($correo, $error);

        return new self(
            enviado: false,
            correo: $correo,
            titulo: 'El código no se envió',
            mensaje: $mensaje,
            sugerencia: $sugerencia,
            detalleTecnico: self::recortar($error->getMessage()),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enviado' => $this->enviado,
            'correo' => $this->correo,
            'titulo' => $this->titulo,
            'mensaje' => $this->mensaje,
            'sugerencia' => $this->sugerencia,
            'detalle_tecnico' => $this->detalleTecnico,
        ];
    }

    /**
     * De la respuesta del servidor de correo a dos frases: qué pasó y qué
     * hacer.
     *
     * EL ORDEN DE LAS PREGUNTAS IMPORTA. Varios servidores usan el mismo
     * código 550 para "esa casilla no existe" y para "te estoy
     * bloqueando", y un rechazo por volumen a veces trae la palabra
     * "denied" adentro. Si se confunden, el mensaje manda a la persona a
     * arreglar lo que no estaba roto.
     *
     * SIEMPRE se aclara que el código YA quedó generado: el usuario no
     * perdió nada y alcanza con reintentar el envío.
     *
     * @return array{0: string, 1: string}
     */
    private static function traducir(string $correo, Throwable $error): array
    {
        $texto = mb_strtolower($error->getMessage());

        // array_any es de PHP 8.4 y el proyecto corre sobre 8.2.
        $contiene = function (array $senales) use ($texto): bool {
            foreach ($senales as $senal) {
                if (str_contains($texto, $senal)) {
                    return true;
                }
            }

            return false;
        };

        // 1. La casilla no existe. Es el caso más común y el único que
        //    puede arreglar quien está mirando el modal.
        if ($contiene(['user unknown', 'no such user', 'does not exist', 'unknown user',
            'recipient address rejected', 'mailbox unavailable', 'invalid recipient'])) {
            return [
                "La dirección {$correo} no existe en el servidor de correo del destinatario, "
                    .'así que el mensaje fue rechazado.',
                'Verifique que el correo esté bien escrito. Si está mal, corríjalo en los datos '
                    .'del usuario y vuelva a enviar el código.',
            ];
        }

        // 2. Nos frenaron por volumen. Es temporal y se resuelve esperando.
        if ($contiene(['too much mail', 'too many', 'rate limit', 'throttl',
            'try again later', 'greylist', 'temporarily deferred'])) {
            return [
                'El servidor de correo pidió esperar porque se enviaron muchos correos seguidos. '
                    .'El mensaje no salió.',
                'Espere unos minutos y vuelva a enviar el código. El código ya quedó generado, '
                    .'no hace falta crear nada de nuevo.',
            ];
        }

        // 3. No llegamos al servidor de correo.
        if ($contiene(['connection could not be established', 'connection refused',
            'could not connect', 'timed out', 'timeout', 'network is unreachable'])) {
            return [
                'No se pudo conectar con el servidor de correo, así que el mensaje no salió.',
                'Avise a Sistemas. El código ya quedó generado: cuando el servidor vuelva, '
                    .'alcanza con volver a enviarlo.',
            ];
        }

        // 4. El servidor nos conoce pero no nos deja entrar.
        if ($contiene(['authentication failed', 'auth', '535', 'not authenticated'])) {
            return [
                'El portal no pudo identificarse ante el servidor de correo, así que el '
                    .'mensaje no salió.',
                'Avise a Sistemas: es un problema de configuración del portal, no del usuario.',
            ];
        }

        // 5. Nos bloquearon por filtro o reputación.
        if ($contiene(['blocked', 'blacklist', 'spam', 'reputation', 'policy',
            'spf', 'dkim', 'dmarc', 'not authorized'])) {
            return [
                'El servidor del destinatario rechazó el mensaje por sus filtros de seguridad.',
                'Avise a Sistemas. No es un problema del usuario ni de su dirección.',
            ];
        }

        return [
            'El correo no se pudo enviar.',
            'El código ya quedó generado, así que puede volver a intentarlo. Si vuelve a '
                .'fallar, avise a Sistemas.',
        ];
    }

    private static function vigenciaEnPalabras(int $minutos): string
    {
        if ($minutos >= 1440) {
            $dias = intdiv($minutos, 1440);

            return $dias === 1 ? '1 día' : "{$dias} días";
        }

        if ($minutos >= 60) {
            $horas = intdiv($minutos, 60);

            return $horas === 1 ? '1 hora' : "{$horas} horas";
        }

        return "{$minutos} minutos";
    }

    /**
     * El detalle técnico se recorta a la primera línea: el resto es la
     * traza de PHP, que no aporta nada al que lo copia para pasárselo a
     * Sistemas y sí convierte el modal en un muro de texto.
     */
    private static function recortar(string $mensaje): string
    {
        $primeraLinea = trim(strtok($mensaje, "\n") ?: $mensaje);

        return mb_strimwidth($primeraLinea, 0, 300, '...');
    }

    /** True si el fallo vino del servidor de correo y no del propio portal. */
    public static function esFalloDeCorreo(Throwable $error): bool
    {
        return $error instanceof TransportExceptionInterface;
    }
}
