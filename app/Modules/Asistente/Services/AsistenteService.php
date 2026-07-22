<?php

namespace App\Modules\Asistente\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Configuraciones\Models\BotRegla;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AsistenteService
{
    public function __construct(protected AsistenteContextoService $contextoService)
    {
    }

    public function responder(Usuario $usuario, int $idEmpresaActiva, string $mensaje, array $historial = []): string
    {
        $contexto = $this->contextoService->generar($usuario, $idEmpresaActiva);
        $persona = $this->armarPersona();

        $mensajes = [
            ['role' => 'system', 'content' => $persona . "\n\nCONTEXTO REAL DEL USUARIO:\n{$contexto}"],
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
                return $respuesta->json('choices.0.message.content') ?? $this->respuestaRespaldo($mensaje);
            }

            Log::warning('Groq respondió con error', ['status' => $respuesta->status(), 'body' => $respuesta->body()]);
        } catch (\Throwable $e) {
            Log::error('Error llamando a Groq', ['error' => $e->getMessage()]);
        }

        return $this->respuestaRespaldo($mensaje);
    }

    protected function armarPersona(): string
    {
        $reglas = BotRegla::where('Tipo', 'Persona')->where('Activo', 1)->orderBy('Orden')->pluck('Contenido');

        if ($reglas->isEmpty()) {
            return 'Eres Hana, la asistente virtual del Portal de Proveedores de Hanaska.';
        }

        return $reglas->implode("\n");
    }

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

        return $default?->Contenido ?? 'En este momento no puedo responder. Intenta de nuevo más tarde.';
    }
}