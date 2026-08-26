<?php

namespace App\Shared;

use Illuminate\Support\Facades\Log;

/**
 * Reduce imágenes grandes antes de guardarlas.
 *
 * POR QUÉ EXISTE: la imagen de fondo del login que había subida pesaba 1.8 MB
 * y medía 2560x1440. Se muestra como fondo de media pantalla, o sea que el
 * navegador descargaba casi 2 MB para dibujar algo que necesitaba menos de
 * una quinta parte de esos píxeles. Y como se sirve en la pantalla de login,
 * era lo primero que veía CUALQUIERA que entrara al portal.
 *
 * El arreglo de fondo no es optimizar la que ya está subida (eso se hace una
 * vez), es que no se pueda volver a subir una sin optimizar. Por eso esto
 * corre en el momento de guardar.
 *
 * USA GD, que ya viene con el PHP de este servidor (no hace falta imagick ni
 * un binario externo). Si por lo que sea GD no está o el archivo no se puede
 * procesar, se devuelve el original SIN TOCAR: es preferible guardar una
 * imagen pesada que perder la subida del usuario.
 *
 * No convierte a WebP a propósito: daría archivos aún más chicos, pero el
 * nombre y la extensión del archivo se guardan en base de datos y los sirve
 * un symlink estático, así que cambiar el formato implica tocar más piezas.
 * Redimensionar y recomprimir en el mismo formato ya recorta ~90% del peso.
 */
class OptimizadorImagen
{
    /**
     * Ancho máximo. 1920 cubre una pantalla Full HD completa, que es el caso
     * más grande real para un fondo: por encima de eso no se gana nitidez
     * visible y solo se paga peso.
     */
    public const ANCHO_MAXIMO = 1920;

    /** Calidad JPEG. 82 es el punto donde deja de notarse la diferencia. */
    public const CALIDAD_JPEG = 82;

    /**
     * Optimiza el archivo EN SU LUGAR si conviene. Devuelve true si lo
     * cambió.
     *
     * "Si conviene" quiere decir: es un JPEG o PNG que GD puede leer y que
     * además es más ancho que ANCHO_MAXIMO, o pesa de más. Una imagen ya
     * chica no se toca -> recomprimirla solo la degradaría.
     */
    public function optimizarEnSitio(string $ruta): bool
    {
        if (! function_exists('imagecreatefromjpeg')) {
            return false;
        }

        try {
            $info = @getimagesize($ruta);

            if ($info === false) {
                return false;
            }

            [$ancho, $alto] = $info;
            $tipo = $info[2];

            if (! in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                return false;
            }

            $pesoOriginal = filesize($ruta);

            // Ya es chica y liviana: no hay nada que ganar.
            if ($ancho <= self::ANCHO_MAXIMO && $pesoOriginal < 400 * 1024) {
                return false;
            }

            $original = $tipo === IMAGETYPE_JPEG
                ? @imagecreatefromjpeg($ruta)
                : @imagecreatefrompng($ruta);

            if ($original === false) {
                return false;
            }

            $anchoNuevo = min($ancho, self::ANCHO_MAXIMO);
            $altoNuevo = (int) round($alto * ($anchoNuevo / $ancho));

            $destino = imagecreatetruecolor($anchoNuevo, $altoNuevo);

            // Los PNG pueden tener transparencia; sin esto el fondo
            // transparente sale negro.
            if ($tipo === IMAGETYPE_PNG) {
                imagealphablending($destino, false);
                imagesavealpha($destino, true);
            }

            imagecopyresampled($destino, $original, 0, 0, 0, 0, $anchoNuevo, $altoNuevo, $ancho, $alto);

            // Se escribe a un temporal y solo se reemplaza si salió bien y
            // quedó más liviano: así una recompresión que engorde el archivo
            // (pasa con algunos PNG) no lo empeora.
            $temporal = $ruta.'.opt';

            $ok = $tipo === IMAGETYPE_JPEG
                ? imagejpeg($destino, $temporal, self::CALIDAD_JPEG)
                : imagepng($destino, $temporal, 8);

            imagedestroy($original);
            imagedestroy($destino);

            if (! $ok || ! is_file($temporal)) {
                @unlink($temporal);

                return false;
            }

            if (filesize($temporal) >= $pesoOriginal) {
                @unlink($temporal);

                return false;
            }

            $pesoNuevo = filesize($temporal);
            rename($temporal, $ruta);

            Log::info('Imagen optimizada al subirla', [
                'ruta' => basename($ruta),
                'antes_kb' => (int) round($pesoOriginal / 1024),
                'despues_kb' => (int) round($pesoNuevo / 1024),
                'dimensiones' => "{$ancho}x{$alto} -> {$anchoNuevo}x{$altoNuevo}",
            ]);

            return true;
        } catch (\Throwable $e) {
            // Nunca romper una subida por no haber podido optimizar.
            Log::warning('No se pudo optimizar la imagen, se guarda como vino', [
                'ruta' => basename($ruta),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
