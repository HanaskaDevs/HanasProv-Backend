<?php

namespace App\Modules\Asistente\Services;

use App\Modules\Auth\Models\Usuario;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AsistenteService
{
    public function __construct(protected AsistenteContextoService $contextoService)
    {
    }

    protected const PERSONA = <<<'TXT'
Eres Hana, la asistente virtual del Portal de Proveedores de Hanaska. Eres cálida, profesional y directa.
Hablas siempre en español neutro, en frases cortas y claras.

REGLAS ESTRICTAS:
- SOLO puedes afirmar datos que aparezcan explícitamente en el bloque "CONTEXTO REAL DEL USUARIO" que se te entrega. Nunca inventes cantidades, estados ni fechas que no estén ahí.
- Si te preguntan algo que no está en el contexto ni sabes con certeza cómo funciona el portal, dilo honestamente y sugiere contactar al equipo correspondiente (ej. Sistemas o el usuario que gestiona su cuenta).
- Explica procesos del portal de forma sencilla: Ficha de Proveedor, Documentación, Ficha de Productos, Pedidos, Reclamos y Calificación.
- Si el proveedor tiene productos EN REVISIÓN (bloqueados), explícale que debe esperar la calificación de un administrador antes de poder editar o agregar productos nuevos.
- Nunca reveles información de otra empresa o de otro proveedor distinto al que te está hablando.
- Sé breve: máximo 4-5 líneas por respuesta salvo que te pidan explícitamente más detalle.
- Los proveedores NUNCA pueden crear reclamos, solo el personal interno de Hanaska puede hacerlo. El proveedor solo ve y responde reclamos ya existentes.
- Diferencia siempre entre "tu empresa" (la del proveedor que te habla) y la "empresa del grupo Hanaska" con la que trabaja (ej. Caterfood) — nunca las confundas.
- Cuando te pregunten "en qué empresa estoy" o similar, responde SIEMPRE con este formato exacto: "Actualmente estás trabajando con la empresa [empresa Hanaska activa] de Hanaska. Tu empresa proveedora registrada es [nombre del proveedor]." Nunca digas "eres proveedor de la empresa X" refiriéndote a la empresa Hanaska, ni mezcles los dos nombres.
- Si el usuario pregunta cómo hacer algo paso a paso dentro del portal (cómo subir documentos, cómo llenar su ficha, cómo agregar productos), responde brevemente Y agrega al final, en una línea aparte, exactamente este texto: [ABRIR_GUIA]. No expliques ese texto, solo agrégalo tal cual al final de tu respuesta cuando aplique.
TXT;

    protected const RESPUESTAS_RESPALDO = [
        'ficha' => 'Para completar tu Ficha de Proveedor, ve a la opción "Mi Ficha" en el menú superior y llena la información general, clase de proveedor y categoría de productos.',
        'documento' => 'En "Documentación" puedes subir en PDF todos los documentos obligatorios y opcionales de tu empresa.',
        'producto' => 'En "Ficha Productos" puedes registrar tus productos con su ficha técnica, análisis y carta de alérgenos, y luego enviarlos a calificación.',
        'pedido' => 'En "Pedidos" puedes ver tus pedidos de compra abiertos y cerrados, y actualizar la información desde el botón correspondiente.',
        'reclamo' => 'En "Reclamos" puedes ver y responder los reclamos abiertos sobre tu empresa. Solo quien crea el reclamo puede cerrarlo.',
        'default' => 'En este momento no tengo conexión con mi motor de respuestas, pero puedo ayudarte con dudas básicas sobre Ficha, Documentación, Productos, Pedidos o Reclamos. Cuéntame más sobre tu duda.',
    ];

    /**
     * @param array<int, array{rol: string, contenido: string}> $historial
     */
    public function responder(Usuario $usuario, int $idEmpresaActiva, string $mensaje, array $historial = []): string
    {
        $contexto = $this->contextoService->generar($usuario, $idEmpresaActiva);

        $mensajes = [
            ['role' => 'system', 'content' => self::PERSONA . "\n\nCONTEXTO REAL DEL USUARIO:\n{$contexto}"],
        ];

        foreach ($historial as $item) {
            $mensajes[] = ['role' => $item['rol'] === 'usuario' ? 'user' : 'assistant', 'content' => $item['contenido']];
        }

        $mensajes[] = ['role' => 'user', 'content' => $mensaje];

        try {
            $respuesta = Http::withToken(config('services.groq.api_key'))
                ->timeout(15)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => config('services.groq.model'),
                    'messages' => $mensajes,
                    'temperature' => 0.4,
                    'max_tokens' => 400,
                ]);

            if ($respuesta->successful()) {
                return $respuesta->json('choices.0.message.content') ?? self::RESPUESTAS_RESPALDO['default'];
            }

            Log::warning('Groq respondió con error', ['status' => $respuesta->status(), 'body' => $respuesta->body()]);
        } catch (\Throwable $e) {
            Log::error('Error llamando a Groq', ['error' => $e->getMessage()]);
        }

        return $this->respuestaRespaldo($mensaje);
    }

    protected function respuestaRespaldo(string $mensaje): string
    {
        $texto = mb_strtolower($mensaje);

        foreach (self::RESPUESTAS_RESPALDO as $clave => $respuesta) {
            if ($clave !== 'default' && str_contains($texto, $clave)) {
                return $respuesta;
            }
        }

        return self::RESPUESTAS_RESPALDO['default'];
    }
}