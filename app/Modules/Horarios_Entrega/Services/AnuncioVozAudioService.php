<?php

namespace App\Modules\Horarios_Entrega\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Convierte la frase de un anuncio del Modo TV en un WAV con Piper (ver
 * config/anuncios_voz.php). El texto lo arma el frontend
 * (MENSAJES_POR_ESTADO en anunciosVoz.ts); acá solo se limpia y se acota.
 */
class AnuncioVozAudioService
{
    /** Ruta del WAV de la frase; lo genera si todavía no existe. */
    public function rutaAudio(string $texto): string
    {
        $texto = $this->limpiar($texto);

        if ($texto === '') {
            throw new RuntimeException('Frase vacía.');
        }

        $carpeta = config('anuncios_voz.carpeta');
        // El modelo y la lentitud entran en el nombre: si se cambia la voz,
        // no se reutilizan audios con la anterior.
        $clave = sha1(config('anuncios_voz.modelo').'|'.config('anuncios_voz.lentitud').'|'.$texto);
        $ruta = $carpeta.'/'.$clave.'.wav';

        if (is_file($ruta)) {
            // Marca de uso, para que la limpieza de routes/console.php solo
            // borre las frases que dejaron de pedirse.
            @touch($ruta);

            return $ruta;
        }

        if (! is_dir($carpeta) && ! @mkdir($carpeta, 02775, true) && ! is_dir($carpeta)) {
            throw new RuntimeException("No se pudo crear {$carpeta}.");
        }

        // A un temporal y después rename: dos TVs pidiendo la misma frase a
        // la vez no pueden leer un WAV a medio escribir.
        $temporal = $ruta.'.'.bin2hex(random_bytes(4)).'.tmp';

        $proceso = new Process([
            config('anuncios_voz.piper'),
            '--model', config('anuncios_voz.modelo'),
            '--length_scale', (string) config('anuncios_voz.lentitud'),
            '--output_file', $temporal,
        ]);
        $proceso->setInput($texto);
        $proceso->setTimeout(config('anuncios_voz.timeout'));
        $proceso->run();

        // Piper sale con 0 aunque no haya podido escribir el archivo (por
        // ejemplo, sin permiso en la carpeta): se mira el archivo, no solo
        // el código de salida.
        if (! $proceso->isSuccessful() || ! is_file($temporal) || filesize($temporal) < 1000) {
            @unlink($temporal);
            Log::error('Anuncios por voz: Piper no generó el audio.', [
                'codigo' => $proceso->getExitCode(),
                'error' => mb_substr($proceso->getErrorOutput(), -500),
            ]);

            throw new RuntimeException('No se pudo generar el audio.');
        }

        rename($temporal, $ruta);

        return $ruta;
    }

    private function limpiar(string $texto): string
    {
        // Sin caracteres de control y con espacios simples: dos frases que
        // difieren solo en espacios son la misma frase (y el mismo archivo).
        $texto = preg_replace('/[\p{C}]+/u', ' ', $texto) ?? '';
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');

        return mb_substr($texto, 0, (int) config('anuncios_voz.max_caracteres'));
    }
}
