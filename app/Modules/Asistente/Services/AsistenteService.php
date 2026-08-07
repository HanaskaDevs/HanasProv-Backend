<?php

namespace App\Modules\Asistente\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Configuraciones\Models\BotRegla;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AsistenteService
{
    // Id_Estado_Proveedor de "Aprobado" (ver RolEstadoProveedorSeeder) ->
    // mismo criterio hardcodeado-con-comentario que ya usa
    // CalificacionProveedorService, Rol/Estado_Proveedor no tienen CRUD.
    protected const ESTADO_PROVEEDOR_APROBADO = 2;

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

    /**
     * Mensaje proactivo de Hana, SIN que el proveedor tenga que abrir el
     * chat: se muestra una única vez, la primera vez que un proveedor
     * recién Aprobado entra al portal (ver Felicitacion_Bienvenida_Mostrada
     * en Proveedor -> se marca en true acá mismo, atómico con la
     * lectura, para que dos pestañas abiertas a la vez no lo muestren
     * doble). Null si no corresponde (no es proveedor, no está
     * Aprobado, o ya se le mostró antes).
     */
    public function obtenerBienvenidaProactiva(Usuario $usuario, int $idEmpresaActiva): ?string
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            return null;
        }

        $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresaActiva)->first();

        if (! $proveedor || (int) $proveedor->Id_Estado_Proveedor !== self::ESTADO_PROVEEDOR_APROBADO) {
            return null;
        }

        if ($proveedor->Felicitacion_Bienvenida_Mostrada) {
            return null;
        }

        // Se marca ANTES de armar el texto (no después): si algo falla
        // armando el mensaje, preferimos perder este único saludo a
        // arriesgarnos a que quede reintentando en cada carga de página.
        $proveedor->forceFill(['Felicitacion_Bienvenida_Mostrada' => true])->save();

        $ahora = now();
        $saludoHorario = match (true) {
            $ahora->hour < 12 => 'Buenos días',
            $ahora->hour < 19 => 'Buenas tardes',
            default => 'Buenas noches',
        };

        $primerNombre = AsistenteContextoService::primerNombreDe($usuario);
        $nombreEmpresa = $proveedor->empresa?->Nombre_Comercial ?? $proveedor->empresa?->Razon_Social ?? 'Hanaska';

        $saludoConNombre = $primerNombre !== '' ? "{$saludoHorario}, {$primerNombre}" : $saludoHorario;

        return "{$saludoConNombre}! 🎉 Tengo que darte una noticia hermosa: ya revisamos todo tu proceso y "
            . "quedaste como proveedor **Aprobado** de {$nombreEmpresa}. ¡Felicidades, de verdad! "
            . "A partir de ahora ya puedes gestionar tu catálogo de productos con normalidad. "
            . "¿Cómo estás? Cualquier cosa que necesites, aquí estoy para ayudarte 💛";
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