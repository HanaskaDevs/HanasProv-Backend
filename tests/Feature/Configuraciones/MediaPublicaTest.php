<?php

namespace Tests\Feature\Configuraciones;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La ruta pública que sirve los videos del home y la imagen del login.
 *
 * POR QUÉ IMPORTA: es la única ruta pública que lee archivos del disco a
 * partir de una ruta que llega en la URL. Dos cosas tienen que sostenerse
 * siempre — que no se pueda salir de la carpeta multimedia, y que la
 * respuesta traiga la caché larga (sin ella el navegador vuelve a bajar medio
 * megabyte de video en cada visita a la landing, que es el problema que esta
 * ruta vino a resolver).
 */
class MediaPublicaTest extends TestCase
{
    private function crearArchivo(string $ruta, string $contenido = 'contenido de prueba'): void
    {
        Storage::disk('multimedia')->put($ruta, $contenido);
    }

    private function borrarArchivo(string $ruta): void
    {
        Storage::disk('multimedia')->delete($ruta);
    }

    public function test_sirve_un_archivo_sin_estar_autenticado(): void
    {
        // Sin sesión a propósito: es el fondo de la landing y del login.
        $ruta = 'home/prueba_media_publica.mp4';
        $this->crearArchivo($ruta);

        try {
            $this->get("/api/media/{$ruta}")->assertOk();
        } finally {
            $this->borrarArchivo($ruta);
        }
    }

    /**
     * La caché es la razón de ser de esta ruta: el symlink estático servía los
     * mismos archivos SIN ningún header de caché.
     */
    public function test_responde_con_cache_inmutable_y_soporte_de_rango(): void
    {
        $ruta = 'home/prueba_media_cache.mp4';
        $this->crearArchivo($ruta);

        try {
            $respuesta = $this->get("/api/media/{$ruta}");

            $cache = (string) $respuesta->headers->get('Cache-Control');
            $this->assertStringContainsString('immutable', $cache);
            $this->assertStringContainsString('max-age=31536000', $cache);
            $this->assertSame('bytes', $respuesta->headers->get('Accept-Ranges'));
        } finally {
            $this->borrarArchivo($ruta);
        }
    }

    /** La ruta trae barras: 'home/archivo.mp4' tiene que llegar entero. */
    public function test_acepta_rutas_con_subcarpeta(): void
    {
        $ruta = 'login/prueba_media_subcarpeta.jpg';
        $this->crearArchivo($ruta);

        try {
            $this->get("/api/media/{$ruta}")->assertOk();
        } finally {
            $this->borrarArchivo($ruta);
        }
    }

    public function test_un_archivo_que_no_existe_da_404(): void
    {
        $this->get('/api/media/home/no_existe_este_archivo.mp4')->assertNotFound();
    }

    /**
     * Path traversal. El .env está dos niveles arriba de la carpeta
     * multimedia, así que es el objetivo natural del intento.
     */
    public function test_no_deja_salir_de_la_carpeta_multimedia(): void
    {
        foreach ([
            'home/../../.env',
            '../.env',
            '../../.env',
            'home/../../../etc/passwd',
        ] as $intento) {
            $this->get('/api/media/'.$intento)->assertNotFound();
        }
    }
}
