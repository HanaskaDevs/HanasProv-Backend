<?php

namespace App\Modules\Asistente\Services;

use Anthropic\Client;
use Anthropic\Messages\ToolUseBlock;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Configuraciones\Models\BotRegla;
use App\Modules\Proveedores\Models\EstadoProveedor;
use Illuminate\Support\Facades\Log;

/**
 * Hana, la asistente del portal. Corre sobre la API de Claude
 * (Haiku 4.5, el modelo económico de la familia).
 *
 * POR QUÉ HAIKU Y NO UN MODELO MÁS GRANDE: Hana no razona ni investiga.
 * Todo el dato duro (documentos pendientes, pedidos, calificación,
 * contactos) llega YA RESUELTO en el bloque de contexto que arma
 * AsistenteContextoService; el modelo solo tiene que redactarlo en
 * castellano y elegir la sección a la que mandar al usuario. Para eso
 * Haiku alcanza, y cuesta 5 veces menos por token de salida que Sonnet.
 *
 * GASTO: antes de cada llamada se consulta AsistentePresupuestoService.
 * Si se alcanzó el tope semanal o el mensual no se llama a la API y se
 * responde con el texto de respaldo -> el gasto tiene un techo real, no
 * "vigilado".
 *
 * FALLA SIEMPRE HACIA EL RESPALDO: cualquier error (sin clave, sin red,
 * 429, tope alcanzado) termina en respuestaRespaldo(). Hana nunca le
 * muestra un error técnico a un proveedor.
 */
class AsistenteService
{
    /** Tope de vueltas del bucle de herramientas, por si el modelo se enreda. */
    protected const MAX_VUELTAS_HERRAMIENTAS = 4;

    /** Tipos de Bot_Regla que usa el saludo proactivo. */
    public const TIPO_SALUDO = 'Saludo';
    public const TIPO_FRASE = 'Frase';

    public function __construct(
        protected AsistenteContextoService $contextoService,
        protected AsistentePresupuestoService $presupuesto,
        protected AsistenteHerramientas $herramientas,
    ) {
    }

    /**
     * Responde un mensaje.
     *
     * Devuelve el texto y, si el modelo usó una herramienta que trajo filas,
     * también esas filas para que el frontend ofrezca la descarga en Excel.
     *
     * @return array{texto: string, tabla: null|array{titulo: string, filas: list<array<string, mixed>>}}
     */
    public function responder(Usuario $usuario, int $idEmpresaActiva, string $mensaje, array $historial = []): array
    {
        $bloqueo = $this->presupuesto->motivoDeBloqueo();

        if ($bloqueo !== null) {
            // Warning y no error: no es una falla del sistema, es el tope
            // funcionando. Queda en el log para que Sistemas sepa por qué
            // Hana está respondiendo con el respaldo.
            Log::warning('Asistente: llamada omitida por presupuesto', ['motivo' => $bloqueo]);

            return $this->soloTexto($this->respuestaRespaldo($mensaje));
        }

        $apiKey = config('services.anthropic.api_key');

        if (empty($apiKey)) {
            Log::error('Asistente: falta ANTHROPIC_API_KEY en el entorno.');

            return $this->soloTexto($this->respuestaRespaldo($mensaje));
        }

        try {
            $cliente = new Client(apiKey: $apiKey);
            $modelo = (string) config('services.anthropic.model');
            $maxTokens = (int) config('services.anthropic.max_tokens');
            $system = $this->armarSystem($usuario, $idEmpresaActiva);
            $definiciones = $this->herramientas->definiciones($usuario, $idEmpresaActiva);

            $mensajes = $this->armarMensajes($mensaje, $historial);
            $tabla = null;

            // Bucle de herramientas: mientras el modelo pida una, se ejecuta y
            // se le devuelve el resultado. El tope de vueltas evita que un
            // modelo que se enreda pidiendo lo mismo consuma el presupuesto.
            for ($vuelta = 0; $vuelta <= self::MAX_VUELTAS_HERRAMIENTAS; $vuelta++) {
                $respuesta = $cliente->messages->create(
                    model: $modelo,
                    maxTokens: $maxTokens,
                    system: $system,
                    messages: $mensajes,
                    temperature: 0.4,
                    tools: $definiciones !== [] ? $definiciones : null,
                );

                $this->presupuesto->registrar(
                    $respuesta->usage,
                    $usuario->Id_Usuario,
                    $idEmpresaActiva,
                    origen: 'Mensaje'
                );

                if ($respuesta->stopReason !== 'tool_use') {
                    $texto = $this->primerTexto($respuesta->content);

                    // Una respuesta vacía puede pasar si el modelo se corta por
                    // maxTokens antes de escribir nada -> mejor el respaldo que un
                    // globo de chat en blanco.
                    return [
                        'texto' => $texto !== '' ? $texto : $this->respuestaRespaldo($mensaje),
                        'tabla' => $tabla,
                    ];
                }

                // El turno del modelo se devuelve TAL CUAL (con sus bloques
                // tool_use): la API exige que el tool_result venga después del
                // tool_use al que responde.
                $mensajes[] = ['role' => 'assistant', 'content' => $respuesta->content];

                $resultados = [];

                foreach ($respuesta->content as $bloque) {
                    if (! $bloque instanceof ToolUseBlock) {
                        continue;
                    }

                    $salida = $this->herramientas->ejecutar(
                        $bloque->name,
                        (array) $bloque->input,
                        $usuario,
                        $idEmpresaActiva
                    );

                    // Se queda la ÚLTIMA tabla con filas: si el modelo pidió
                    // dos consultas, la del final es la que respondió a lo que
                    // el usuario pidió.
                    if ($salida['filas'] !== []) {
                        $tabla = ['titulo' => $salida['titulo'], 'filas' => $salida['filas']];
                    }

                    $resultados[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $bloque->id,
                        'content' => $salida['texto'],
                    ];
                }

                if ($resultados === []) {
                    // stopReason decía tool_use pero no vino ningún bloque
                    // utilizable: no hay con qué seguir el bucle.
                    break;
                }

                $mensajes[] = ['role' => 'user', 'content' => $resultados];
            }

            Log::warning('Asistente: se agotaron las vueltas de herramientas sin respuesta final');

            return [
                'texto' => 'Encontré los datos pero no llegué a resumirlos. '
                    .($tabla !== null ? 'Puedes descargarlos con el botón de abajo.' : 'Intenta preguntarme de nuevo.'),
                'tabla' => $tabla,
            ];
        } catch (RateLimitException $e) {
            Log::warning('Asistente: Claude devolvió 429 (rate limit)', ['error' => $e->getMessage()]);
        } catch (APIStatusException $e) {
            Log::error('Asistente: Claude devolvió un error de API', [
                'tipo' => $e->type?->value,
                'error' => $e->getMessage(),
            ]);
        } catch (APIConnectionException $e) {
            Log::error('Asistente: no se pudo conectar con Claude', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Asistente: error inesperado llamando a Claude', ['error' => $e->getMessage()]);
        }

        return $this->soloTexto($this->respuestaRespaldo($mensaje));
    }

    /** @return array{texto: string, tabla: null} */
    protected function soloTexto(string $texto): array
    {
        return ['texto' => $texto, 'tabla' => null];
    }

    /**
     * El system prompt en dos bloques: primero quién es Hana y cómo se
     * comporta, después el contexto real de ESTE usuario.
     *
     * Van separados porque son dos cosas distintas: la persona es estable y
     * la misma para todos, el contexto cambia en cada mensaje. Mantenerlos
     * separados deja la puerta abierta a cachear el primero más adelante,
     * sin reescribir nada.
     */
    protected function armarSystem(Usuario $usuario, int $idEmpresaActiva): array
    {
        $guia = $usuario->Tipo_Usuario === 'Proveedor'
            ? AsistenteGuiaPortal::paraProveedor()
            : AsistenteGuiaPortal::paraInterno();

        return [
            // BLOQUE ESTABLE: persona + cómo funciona el portal. Es idéntico
            // para todos los usuarios del mismo tipo y no cambia entre
            // mensajes, por eso va PRIMERO: la caché de la API es un match
            // de prefijo, y si esto estuviera después del contexto (que
            // cambia en cada mensaje) no habría prefijo estable que cachear.
            //
            // OJO — HOY ESTE cacheControl NO HACE NADA, MEDIDO: el bloque
            // pesa 1680 tokens para proveedor y 1290 para interno, y el
            // mínimo cacheable de Haiku 4.5 son 2048 tokens. Por debajo de
            // ese umbral la API ignora el breakpoint EN SILENCIO (no da
            // error, simplemente cobra todo como entrada normal:
            // cache_creation_input_tokens y cache_read_input_tokens vuelven
            // en 0, que es lo que se ve hoy en Asistente_Consumo).
            //
            // Se deja declarado igual porque no cuesta nada y empieza a
            // funcionar solo si la guía crece por encima de 2048 tokens. Si
            // algún día hace falta el ahorro antes de eso, la palanca es
            // subir de modelo (el mínimo de Sonnet/Opus es 1024) o agrandar
            // la guía, NUNCA rellenarla con texto de adorno para llegar al
            // umbral.
            //
            // Y no hace falta el ahorro todavía: a ~0.0035 USD por mensaje,
            // el tope semanal de 5 USD da para unos 1400 mensajes.
            [
                'type' => 'text',
                'text' => $this->armarPersona()."\n\n".$guia,
                'cacheControl' => ['type' => 'ephemeral'],
            ],
            // BLOQUE VOLÁTIL: los datos de ESTE usuario, ahora. Va después
            // del punto de caché a propósito.
            [
                'type' => 'text',
                'text' => "CONTEXTO REAL DEL USUARIO\n"
                    . "Todo lo que sigue son datos verdaderos, leídos de la base ahora mismo. "
                    . "Úsalos tal cual y no inventes ninguna cifra, nombre ni fecha que no esté aquí.\n\n"
                    . $this->contextoService->generar($usuario, $idEmpresaActiva),
            ],
        ];
    }

    /**
     * Historial + mensaje nuevo, en el formato de la API.
     *
     * El primer mensaje SIEMPRE tiene que ser 'user': si el historial que
     * manda el front empieza con una respuesta del bot (pasa cuando Hana
     * abrió la conversación con el saludo proactivo), la API rechaza el
     * request con un 400. Por eso se descarta lo que venga antes del primer
     * turno del usuario.
     */
    protected function armarMensajes(string $mensaje, array $historial): array
    {
        $mensajes = [];

        foreach ($historial as $item) {
            $rol = ($item['rol'] ?? '') === 'usuario' ? 'user' : 'assistant';
            $contenido = trim((string) ($item['contenido'] ?? ''));

            if ($contenido === '') {
                continue;
            }

            if ($mensajes === [] && $rol !== 'user') {
                continue;
            }

            $mensajes[] = ['role' => $rol, 'content' => $contenido];
        }

        $mensajes[] = ['role' => 'user', 'content' => $mensaje];

        return $mensajes;
    }

    /**
     * El primer bloque de texto de la respuesta. content es una lista de
     * bloques polimórficos, así que no se puede asumir que content[0] sea
     * texto.
     */
    protected function primerTexto(array $content): string
    {
        foreach ($content as $bloque) {
            if (($bloque->type ?? null) === 'text') {
                return trim((string) $bloque->text);
            }
        }

        return '';
    }

    /**
     * Mensaje proactivo de Hana, SIN que el proveedor tenga que abrir el
     * chat: se muestra una única vez, la primera vez que un proveedor
     * recién Aprobado entra al portal (ver Felicitacion_Bienvenida_Mostrada
     * en Proveedor -> se marca en true acá mismo, atómico con la
     * lectura, para que dos pestañas abiertas a la vez no lo muestren
     * doble). Null si no corresponde (no es proveedor, no está
     * Aprobado, o ya se le mostró antes).
     *
     * Es texto armado en PHP, no una llamada al modelo: es siempre el mismo
     * mensaje y no tiene sentido pagar tokens por redactarlo.
     */
    public function obtenerBienvenidaProactiva(Usuario $usuario, int $idEmpresaActiva): ?string
    {
        // Primero los saludos configurables: los carga Sistemas desde
        // Configuraciones y aplican a CUALQUIER usuario, interno o proveedor.
        $configurado = $this->saludoConfigurado($usuario);

        if ($configurado !== null) {
            return $configurado;
        }

        return $this->felicitacionProveedorAprobado($usuario, $idEmpresaActiva);
    }

    /**
     * Saludo definido por Sistemas para un usuario concreto.
     *
     * CÓMO SE CONFIGURA: una regla de Bot_Regla con Tipo = 'Saludo', el correo
     * del usuario en Palabra_Clave y el texto en Contenido. Palabra_Clave = '*'
     * aplica a todos.
     *
     * POR QUÉ ASÍ Y NO COMO INSTRUCCIÓN DE PROMPT: el saludo aparece al entrar
     * al portal, SIN que el usuario escriba nada. En ese momento no hay
     * conversación ni llamada al modelo, así que una instrucción del tipo
     * "cuando entre tal usuario, salúdalo" no tenía ninguna manera de
     * ejecutarse. Se intentó cargándola como regla de Persona y no pasó nada,
     * justamente por esto.
     *
     * Y de paso no cuesta tokens: es texto armado en PHP.
     */
    protected function saludoConfigurado(Usuario $usuario): ?string
    {
        $correo = mb_strtolower(trim((string) $usuario->Email));

        $regla = BotRegla::where('Tipo', self::TIPO_SALUDO)
            ->where('Activo', 1)
            // El correo exacto gana sobre el comodín: orderByRaw pone primero
            // las claves que no son '*'.
            ->where(function ($query) use ($correo) {
                $query->whereRaw('LOWER(LTRIM(RTRIM(Palabra_Clave))) = ?', [$correo])
                    ->orWhere('Palabra_Clave', '*');
            })
            ->orderByRaw("CASE WHEN Palabra_Clave = '*' THEN 1 ELSE 0 END")
            ->orderBy('Orden')
            ->first();

        if (! $regla) {
            return null;
        }

        $frase = $this->fraseMotivacional();

        return trim($regla->Contenido).($frase !== null ? "\n\n".$frase : '');
    }

    /**
     * Frase motivacional, elegida AL AZAR entre las cargadas.
     *
     * Antes rotaba por día del año, con la idea de que el usuario viera la
     * misma frase toda la jornada. En la práctica se pidió lo contrario: que
     * las frases se manden aleatoriamente, para que no se repita la misma
     * cada vez. Como el saludo ahora sale UNA sola vez por inicio de sesión
     * (y no en cada navegación), al azar no significa "una frase nueva en
     * cada recarga": significa una distinta en cada login.
     *
     * Las frases se cargan con Bot_Regla de Tipo = 'Frase'. Sin ninguna
     * cargada, devuelve null y el saludo sale solo.
     */
    protected function fraseMotivacional(): ?string
    {
        $frases = BotRegla::where('Tipo', self::TIPO_FRASE)
            ->where('Activo', 1)
            ->pluck('Contenido');

        if ($frases->isEmpty()) {
            return null;
        }

        return trim($frases->random());
    }

    /**
     * Felicitación de proveedor recién Aprobado: se muestra una única vez, la
     * primera vez que entra al portal después de la aprobación (ver
     * Felicitacion_Bienvenida_Mostrada en Proveedor -> se marca en true acá
     * mismo, atómico con la lectura, para que dos pestañas abiertas a la vez
     * no lo muestren doble). Null si no corresponde.
     */
    protected function felicitacionProveedorAprobado(Usuario $usuario, int $idEmpresaActiva): ?string
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            return null;
        }

        $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresaActiva)->first();

        if (! $proveedor || (int) $proveedor->Id_Estado_Proveedor !== EstadoProveedor::APROBADO) {
            return null;
        }

        if ($proveedor->Felicitacion_Bienvenida_Mostrada) {
            return null;
        }

        // Se marca ANTES de armar el texto (no después): si algo falla
        // armando el mensaje, preferimos perder este único saludo a
        // arriesgarnos a que quede reintentando en cada carga de página.
        $proveedor->forceFill(['Felicitacion_Bienvenida_Mostrada' => true])->save();

        $primerNombre = AsistenteContextoService::primerNombreDe($usuario);
        $nombreEmpresa = $proveedor->empresa?->Nombre_Comercial ?? $proveedor->empresa?->Razon_Social ?? 'Hanaska';

        $saludoConNombre = $primerNombre !== ''
            ? "{$this->saludoSegunHora()}, {$primerNombre}"
            : $this->saludoSegunHora();

        return "{$saludoConNombre}! 🎉 Tengo que darte una noticia hermosa: ya revisamos todo tu proceso y "
            . "quedaste como proveedor **Aprobado** de {$nombreEmpresa}. ¡Felicidades, de verdad! "
            . "A partir de ahora ya puedes gestionar tu catálogo de productos con normalidad. "
            . "¿Cómo estás? Cualquier cosa que necesites, aquí estoy para ayudarte 💛";
    }

    protected function saludoSegunHora(): string
    {
        $hora = now()->hour;

        return match (true) {
            $hora < 12 => 'Buenos días',
            $hora < 19 => 'Buenas tardes',
            default => 'Buenas noches',
        };
    }

    /**
     * La persona de Hana. Sale de Bot_Regla si Sistemas cargó reglas por
     * interfaz; si esa tabla está vacía (hoy lo está) se usa este texto,
     * que no es un relleno mínimo a propósito: es el que define el tono y
     * los límites del bot, y tiene que funcionar solo.
     */
    protected function armarPersona(): string
    {
        // Las reglas de Sistemas se AGREGAN a la persona base, no la
        // reemplazan.
        //
        // Antes esto devolvía solo las reglas cuando había alguna, y con eso
        // la primera regla que alguien cargara por interfaz borraba de un
        // golpe TODO el comportamiento definido acá: el tono, los límites, la
        // prohibición de inventar datos y la de no andar repartiendo correos
        // de contacto. Pasó de verdad: se cargó una regla de saludo y el bot
        // perdió el resto de sus instrucciones en el mismo movimiento.
        $reglas = BotRegla::where('Tipo', 'Persona')->where('Activo', 1)->orderBy('Orden')->pluck('Contenido');

        $base = $this->personaBase();

        if ($reglas->isEmpty()) {
            return $base;
        }

        return $base."\n\n"
            ."INSTRUCCIONES ADICIONALES (cargadas por Sistemas desde Configuraciones)\n"
            ."Se suman a lo de arriba. Si alguna contradijera una de las reglas que no se rompen, "
            ."manda la regla de arriba.\n"
            .$reglas->map(fn (string $regla) => '- '.trim($regla))->implode("\n");
    }

    protected function personaBase(): string
    {
        return <<<'TEXTO'
        Eres Hana, la asistente virtual del Portal de Proveedores de Hanaska (Ecuador).

        CÓMO HABLAS
        - En español neutro, cercano y breve. Tuteas.
        - Vas al dato primero y después explicas. Nada de rodeos ni de repetir la pregunta.
        - Respuestas de 2 a 5 frases salvo que te pidan un paso a paso.
        - Puedes usar **negrita** para resaltar un dato y listas cortas para pasos.

        REPORTES Y ARCHIVOS: SÍ PUEDES
        - Cuando una consulta tuya trae filas, el portal muestra un botón
          "Descargar Excel" debajo de tu respuesta, con esas mismas filas. Es
          decir: SÍ generas reportes descargables. Nunca digas que no puedes
          generar archivos ni mandes al usuario a exportarlo a mano de otra
          pantalla.
        - Si te piden un archivo, un reporte o un listado: haz la consulta,
          resume en una o dos frases lo que trajo (cuántos, de qué bodega) y
          cierra con que lo descargue con el botón de abajo.
        - Si la consulta vuelve vacía, revisa el filtro que usaste antes de
          afirmar que no existe nada. Un filtro mal puesto se parece mucho a
          "no hay datos", y no es lo mismo. Vuelve a consultar con el filtro
          correcto antes de contestar.

        LO QUE NO HACES
        - No cierras cada respuesta ofreciendo correos de contacto. Derivas solo
          cuando el usuario tiene un problema que no se resuelve en el portal, y
          solo al área que corresponde. Pedirte un dato o un reporte NO es un
          problema técnico.
        - No dices "no tengo acceso" si tienes una herramienta que puede
          consultarlo: úsala primero.
        - No afirmas que un dato no existe porque tu consulta volvió vacía: eso
          solo dice que ese filtro no trajo nada.

        REGLAS QUE NO SE ROMPEN
        - Solo usas datos del bloque CONTEXTO REAL DEL USUARIO. Si un dato no está ahí,
          dices que no lo tienes a mano y ofreces a dónde ir a verlo. NUNCA inventas
          cifras, fechas, nombres de proveedores ni estados.
        - No hablas de otros proveedores ni de otras empresas del grupo con nadie que no
          tenga acceso a esa información. Si te preguntan por algo fuera de su alcance,
          lo dices con naturalidad y sin dar pistas del dato.
        - No prometes plazos, aprobaciones ni resultados de una calificación.
        - No pides ni repites contraseñas, códigos de activación ni datos bancarios.
        - Si te preguntan algo que no es del portal, lo reconoces y reencaminas la
          conversación a lo que sí puedes ayudar.
        TEXTO;
    }

    /**
     * Qué contesta Hana cuando no hubo llamada al modelo (tope de gasto,
     * error de red, falta la clave). Sistemas puede personalizar estos
     * textos por interfaz con Bot_Regla de tipo 'Respaldo'.
     */
    protected function respuestaRespaldo(string $mensaje): string
    {
        $texto = mb_strtolower($mensaje);

        $reglas = BotRegla::where('Tipo', 'Respaldo')->where('Activo', 1)->orderBy('Orden')->get();

        foreach ($reglas as $regla) {
            if ($regla->Palabra_Clave && $regla->Palabra_Clave !== 'default' && str_contains($texto, $regla->Palabra_Clave)) {
                return $regla->Contenido;
            }
        }

        $default = $reglas->firstWhere('Palabra_Clave', 'default');

        return $default?->Contenido
            ?? 'Ahora mismo no puedo responderte. Puedes revisar lo que necesitas desde el menú lateral, '
                . 'o escribir a sistemas@hanaska.com si es algo urgente.';
    }
}
