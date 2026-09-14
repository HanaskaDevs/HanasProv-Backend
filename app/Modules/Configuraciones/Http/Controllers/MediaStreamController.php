<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sirve los archivos del disco 'multimedia' (imágenes/video de Login y
 * Landing) a través de una respuesta que SÍ soporta HTTP Range requests.
 *
 * Por qué existe esto en vez de dejar que se sirvan como archivo estático
 * directo (como antes, vía symlink public/media): el servidor embebido de
 * PHP ('php artisan serve', usado en este servidor de staging) no soporta
 * Range para archivos estáticos. Sin Range, el navegador/WebView no puede
 * reproducir <video> (aunque sí puede cargar <img> completa de una sola
 * vez, por eso solo el video fallaba).
 *
 * response()->file() en Laravel devuelve un BinaryFileResponse (Symfony),
 * que SÍ maneja Range a nivel de aplicación -> funciona sin importar si
 * detrás corre artisan serve, Nginx o Apache.
 */
class MediaStreamController extends Controller
{
    public function show(string $path): BinaryFileResponse|Response
    {
        $disco = Storage::disk('multimedia');

        // Path traversal: bloquear cualquier intento de salir de la carpeta
        // multimedia con '..' en la ruta pedida.
        if (str_contains($path, '..')) {
            abort(404);
        }

        if (! $disco->exists($path)) {
            abort(404);
        }

        // Cache-Control largo: estos archivos (videos/imágenes del home y
        // login) casi nunca cambian, y cuando cambian es porque alguien
        // subió uno nuevo desde Configuraciones -> eso genera un nombre de
        // archivo NUEVO (ver ConfiguracionService), no sobrescribe el
        // viejo. Por eso es seguro cachear agresivo: la URL vieja jamás va
        // a servir contenido distinto del que ya se cacheó.
        //
        // Sin esto, cada vez que se abre la app/página se vuelve a pedir
        // el archivo completo de nuevo, aunque no haya cambiado nada.
        return response()->file($disco->path($path), [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
