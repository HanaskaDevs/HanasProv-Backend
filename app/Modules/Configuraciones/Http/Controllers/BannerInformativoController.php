<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Banner informativo.
 *
 * mostrar() lo consulta CUALQUIER usuario logueado al entrar, así que
 * devuelve solo las piezas activas y, con el banner apagado, contesta lo
 * mínimo sin tocar la tabla de piezas. El resto es administración de
 * Sistemas, validada en el Service.
 */
class BannerInformativoController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService) {}

    public function mostrar(Request $request): JsonResponse
    {
        // Se pasa el usuario: es lo que permite no mandarle el contenido a
        // quien no le corresponde por audiencia.
        return response()->json(
            $this->configuracionService->obtenerBannerInformativo(para: $request->user())
        );
    }

    /** Vista de administración: incluye las piezas desactivadas. */
    public function index(): JsonResponse
    {
        return response()->json([
            ...$this->configuracionService->obtenerBannerInformativo(incluirInactivas: true),
            // Para que la pantalla pueda rechazar un archivo grande ANTES
            // de gastar la subida, y con el número real del servidor.
            'max_mb' => (int) round(self::maximoKilobytes() / 1024),
        ]);
    }

    public function guardar(Request $request): JsonResponse
    {
        return response()->json(
            $this->configuracionService->guardarBannerInformativo($request->user(), $request->all())
        );
    }

    public function crearPieza(Request $request): JsonResponse
    {
        $this->validarPieza($request, mediaObligatoria: true);

        return response()->json(
            $this->configuracionService->crearPiezaBanner(
                $request->user(),
                $request->all(),
                $request->file('media')
            ),
            201
        );
    }

    public function actualizarPieza(Request $request, int $pieza): JsonResponse
    {
        $this->validarPieza($request, mediaObligatoria: false);

        return response()->json(
            $this->configuracionService->actualizarPiezaBanner(
                $request->user(),
                $pieza,
                $request->all(),
                $request->file('media')
            )
        );
    }

    public function eliminarPieza(Request $request, int $pieza): JsonResponse
    {
        $this->configuracionService->eliminarPiezaBanner($request->user(), $pieza);

        return response()->json(['message' => 'Pieza eliminada.']);
    }

    /**
     * EL TOPE SALE DE PHP, NO DE UN NÚMERO ESCRITO A MANO.
     *
     * Antes decía 20 MB mientras php.ini aceptaba 10. Un archivo de 12 MB
     * no llegaba a Laravel siquiera: PHP lo descarta antes, y la petición
     * entra con $_POST y $_FILES VACÍOS. Desde el navegador eso se ve como
     * una pantalla colgada que después contesta "falta el archivo", sin
     * ninguna pista de que el problema era el tamaño.
     *
     * Leyendo el límite real, el mensaje nunca promete lo que el servidor
     * no puede recibir, y el valor se adapta solo si en producción php.ini
     * tiene otra configuración.
     */
    protected function validarPieza(Request $request, bool $mediaObligatoria): void
    {
        $request->validate([
            'media' => [
                $mediaObligatoria ? 'required' : 'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,gif,mp4,webm',
                'max:'.self::maximoKilobytes(),
            ],
            'titulo' => ['nullable', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'activo' => ['nullable', 'boolean'],
        ], [
            'media.max' => 'El archivo supera el máximo de '.round(self::maximoKilobytes() / 1024).' MB que acepta el servidor.',
            'media.mimes' => 'Formato no admitido. Usa JPG, PNG, WEBP o GIF para imágenes, y MP4 o WEBM para video.',
            'media.required' => 'Elige una imagen o un video.',
        ]);
    }

    /**
     * Cuántos KB se pueden subir de verdad: el MENOR entre
     * upload_max_filesize (el archivo) y post_max_size (toda la petición,
     * archivo incluido). Mandar más que cualquiera de los dos termina en
     * una petición vacía.
     */
    public static function maximoKilobytes(): int
    {
        $enKb = fn (string $directiva) => (int) (self::aBytes((string) ini_get($directiva)) / 1024);

        $limite = min($enKb('upload_max_filesize'), $enKb('post_max_size'));

        // Un margen para los demás campos del formulario, que también
        // cuentan dentro de post_max_size.
        return max(1, $limite - 256);
    }

    /** "10M" / "512K" / "1G" -> bytes. */
    protected static function aBytes(string $valor): int
    {
        $valor = trim($valor);

        if ($valor === '') {
            return 0;
        }

        $numero = (int) $valor;

        return match (strtolower(substr($valor, -1))) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }
}
