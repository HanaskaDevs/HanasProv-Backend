<?php

namespace Tests\Feature\Configuraciones;

use App\Modules\Configuraciones\Services\ConfiguracionService;
use Tests\TestCase;

/**
 * Video tutorial del proveedor (01-oct-2026): Sistemas pega la URL de
 * YouTube en Configuraciones y el proveedor lo ve desde su panel.
 */
class VideoTutorialTest extends TestCase
{
    public function test_sistemas_guarda_la_url_y_recibe_la_de_embed(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $respuesta = $this->postJson(
            '/api/configuraciones/video-tutorial',
            ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s'],
            $this->cabecerasComo($sistemas, $empresa)
        );

        $respuesta->assertOk();
        // Se devuelve la URL tal cual la pegó: el formulario tiene que
        // volver a mostrarle lo que escribió.
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s', $respuesta->json('url'));
        $this->assertSame('dQw4w9WgXcQ', $respuesta->json('video_id'));
        $this->assertStringContainsString('/embed/dQw4w9WgXcQ', (string) $respuesta->json('url_embed'));
    }

    /** Las cuatro formas con las que alguien llega pegando un enlace. */
    public function test_reconoce_las_variantes_de_enlace_de_youtube(): void
    {
        $servicio = app(ConfiguracionService::class);

        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/watch?list=PL123&v=dQw4w9WgXcQ',
        ] as $url) {
            $this->assertSame('dQw4w9WgXcQ', $servicio::idDeVideoYoutube($url), "No reconoció: {$url}");
        }

        $this->assertNull($servicio::idDeVideoYoutube('https://vimeo.com/123456'));
        $this->assertNull($servicio::idDeVideoYoutube('cualquier texto'));
    }

    /**
     * Una URL que no es de YouTube se rechaza AL GUARDAR. Si se aceptara,
     * el error se descubriría recién cuando un proveedor abre el modal y
     * se encuentra con un recuadro negro.
     */
    public function test_rechaza_un_enlace_que_no_es_de_youtube(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $this->postJson(
            '/api/configuraciones/video-tutorial',
            ['url' => 'https://vimeo.com/123456'],
            $this->cabecerasComo($sistemas, $empresa)
        )->assertStatus(422);
    }

    /** Vaciar el campo es la forma de apagar el botón sin desplegar nada. */
    public function test_vaciar_la_url_quita_el_video(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $this->postJson('/api/configuraciones/video-tutorial', ['url' => 'https://youtu.be/dQw4w9WgXcQ'], $cabeceras)
            ->assertOk();

        $respuesta = $this->postJson('/api/configuraciones/video-tutorial', ['url' => ''], $cabeceras)->assertOk();

        $this->assertNull($respuesta->json('url'));
        $this->assertNull($respuesta->json('url_embed'));
    }

    public function test_el_proveedor_puede_leer_el_video_pero_no_cambiarlo(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [$usuarioProveedor] = $this->crearProveedorConUsuario($empresa);

        $this->postJson(
            '/api/configuraciones/video-tutorial',
            ['url' => 'https://youtu.be/dQw4w9WgXcQ'],
            $this->cabecerasComo($sistemas, $empresa)
        )->assertOk();

        $cabecerasProveedor = $this->cabecerasComo($usuarioProveedor, $empresa);

        // Leer sí: es quien abre el modal.
        $this->getJson('/api/video-tutorial', $cabecerasProveedor)
            ->assertOk()
            ->assertJsonPath('video_id', 'dQw4w9WgXcQ');

        // Guardar no.
        $this->postJson(
            '/api/configuraciones/video-tutorial',
            ['url' => 'https://youtu.be/otroVideo11'],
            $cabecerasProveedor
        )->assertForbidden();
    }

    public function test_un_rol_interno_que_no_es_sistemas_tampoco_puede_cambiarlo(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->postJson(
            '/api/configuraciones/video-tutorial',
            ['url' => 'https://youtu.be/dQw4w9WgXcQ'],
            $this->cabecerasComo($compras, $empresa)
        )->assertForbidden();
    }
}
