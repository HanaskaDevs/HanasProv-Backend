<?php

namespace Tests\Feature\Configuraciones;

use App\Modules\Configuraciones\Http\Controllers\BannerInformativoController;
use App\Modules\Configuraciones\Models\BannerInformativo;
use App\Modules\Configuraciones\Models\Configuracion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Banner informativo (02-oct-2026): Sistemas lo enciende y lo ve todo el
 * que inicia sesión, hasta que lo cierra con la X.
 */
class BannerInformativoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sin esto los archivos de prueba irían al repositorio real.
        Storage::fake('multimedia');

        /*
         * PUNTO DE PARTIDA EXPLÍCITO. Los tests corren contra la MISMA base
         * del .env (ver Tests\TestCase), así que el banner de verdad —el
         * que Sistemas dejó encendido con su afiche— también está acá. Sin
         * esto, un test que comprueba "apagado no muestra nada" leía el
         * interruptor real en 1 y fallaba, y los que miran 'piezas.0'
         * encontraban primero la imagen real en vez de la del test.
         *
         * Borrar acá es seguro y NO toca los datos reales:
         * DatabaseTransactions revierte todo al terminar cada test.
         */
        BannerInformativo::query()->delete();
        Configuracion::establecer('banner_informativo_activo', '0', 1);
    }

    private function imagen(): UploadedFile
    {
        return UploadedFile::fake()->image('aviso.jpg', 800, 400);
    }

    /**
     * Enciende el banner. audiencia y frecuencia son obligatorias.
     *
     * Lleva un título por defecto para que HAYA CONTENIDO: un banner
     * encendido pero vacío se devuelve como inactivo a propósito (ver
     * test_encendido_pero_vacio_no_se_le_muestra_a_nadie). Los tests que
     * quieren probar justamente ese caso lo pisan con 'titulo' => ''.
     */
    private function encender(array $cabeceras, array $extra = [])
    {
        return $this->putJson('/api/configuraciones/banner-informativo', [
            'activo' => true,
            'audiencia' => 'todos',
            'frecuencia' => 'una_vez',
            'titulo' => 'Aviso de prueba',
            ...$extra,
        ], $cabeceras);
    }

    public function test_apagado_no_devuelve_nada_a_los_usuarios(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [$proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->post(
            '/api/configuraciones/banner-informativo/piezas',
            ['media' => $this->imagen(), 'titulo' => 'Mantenimiento'],
            $this->cabecerasComo($sistemas, $empresa)
        )->assertSuccessful();

        // La pieza existe, pero el interruptor está apagado.
        $respuesta = $this->getJson('/api/banner-informativo', $this->cabecerasComo($proveedor, $empresa));

        $respuesta->assertOk();
        $this->assertFalse($respuesta->json('activo'));
        $this->assertSame([], $respuesta->json('piezas'));
    }

    public function test_encendido_lo_ve_cualquier_usuario_logueado(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [$proveedor] = $this->crearProveedorConUsuario($empresa);
        $cabecerasSistemas = $this->cabecerasComo($sistemas, $empresa);

        $this->post(
            '/api/configuraciones/banner-informativo/piezas',
            ['media' => $this->imagen(), 'titulo' => 'Horario especial'],
            $cabecerasSistemas
        )->assertSuccessful();

        $this->encender($cabecerasSistemas, [
            'titulo' => 'Aviso importante',
            'mensaje' => 'Esta semana cambia el horario de recepción.',
        ])->assertOk();

        $respuesta = $this->getJson('/api/banner-informativo', $this->cabecerasComo($proveedor, $empresa));

        $respuesta->assertOk();
        $this->assertTrue($respuesta->json('activo'));
        $this->assertSame('Aviso importante', $respuesta->json('titulo'));
        $this->assertCount(1, $respuesta->json('piezas'));
        $this->assertSame('imagen', $respuesta->json('piezas.0.tipo_media'));
        $this->assertNotNull($respuesta->json('piezas.0.url_media'));
    }

    /** Varias imágenes: el banner las devuelve en orden, como carrusel. */
    public function test_admite_varias_piezas_y_respeta_el_orden(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        foreach ([['Tercera', 3], ['Primera', 1], ['Segunda', 2]] as [$titulo, $orden]) {
            $this->post(
                '/api/configuraciones/banner-informativo/piezas',
                ['media' => $this->imagen(), 'titulo' => $titulo, 'orden' => $orden],
                $cabeceras
            )->assertSuccessful();
        }

        $this->encender($cabeceras)->assertOk();

        $titulos = collect($this->getJson('/api/banner-informativo', $cabeceras)->json('piezas'))
            ->pluck('titulo')
            ->all();

        $this->assertSame(['Primera', 'Segunda', 'Tercera'], $titulos);
    }

    /** También admite un video. */
    public function test_admite_un_video(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $this->post(
            '/api/configuraciones/banner-informativo/piezas',
            ['media' => UploadedFile::fake()->create('aviso.mp4', 500, 'video/mp4')],
            $cabeceras
        )->assertSuccessful();

        $this->encender($cabeceras)->assertOk();

        $this->assertSame(
            'video',
            $this->getJson('/api/banner-informativo', $cabeceras)->json('piezas.0.tipo_media')
        );
    }

    /** Una pieza desactivada se le esconde al usuario, pero Sistemas la sigue viendo. */
    public function test_una_pieza_desactivada_no_se_le_muestra_al_usuario(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $idPieza = $this->post(
            '/api/configuraciones/banner-informativo/piezas',
            ['media' => $this->imagen()],
            $cabeceras
        )->assertSuccessful()->json('Id_Banner_Informativo');

        $this->encender($cabeceras)->assertOk();

        $this->post(
            "/api/configuraciones/banner-informativo/piezas/{$idPieza}",
            ['activo' => false],
            $cabeceras
        )->assertOk();

        $this->assertCount(0, $this->getJson('/api/banner-informativo', $cabeceras)->json('piezas'));
        $this->assertCount(1, $this->getJson('/api/configuraciones/banner-informativo', $cabeceras)->json('piezas'));
    }

    /**
     * LA VERSIÓN ES LO QUE HACE QUE UN AVISO NUEVO VUELVA A APARECER. El
     * navegador recuerda cuál cerró; si Sistemas cambia algo y la versión
     * no cambiara, quien ya lo cerró no volvería a verlo nunca.
     */
    public function test_cualquier_cambio_renueva_la_version(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $this->encender($cabeceras)->assertOk();
        $primera = $this->getJson('/api/banner-informativo', $cabeceras)->json('version');

        $this->assertNotNull($primera);

        // Un segundo después, para que la marca de tiempo cambie de verdad.
        $this->travel(2)->seconds();

        $this->post(
            '/api/configuraciones/banner-informativo/piezas',
            ['media' => $this->imagen()],
            $cabeceras
        )->assertSuccessful();

        $this->assertNotSame(
            $primera,
            $this->getJson('/api/banner-informativo', $cabeceras)->json('version'),
            'Agregar una pieza tiene que renovar la versión.'
        );
    }

    public function test_borrar_una_pieza_la_saca_del_banner(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $idPieza = $this->post(
            '/api/configuraciones/banner-informativo/piezas',
            ['media' => $this->imagen()],
            $cabeceras
        )->assertSuccessful()->json('Id_Banner_Informativo');

        $this->deleteJson("/api/configuraciones/banner-informativo/piezas/{$idPieza}", [], $cabeceras)->assertOk();

        $this->assertFalse(BannerInformativo::where('Id_Banner_Informativo', $idPieza)->exists());
    }

    public function test_solo_sistemas_puede_administrarlo(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        $cabeceras = $this->cabecerasComo($compras, $empresa);

        $this->encender($cabeceras)->assertForbidden();

        $this->post(
            '/api/configuraciones/banner-informativo/piezas',
            ['media' => $this->imagen()],
            $cabeceras
        )->assertForbidden();
    }

    /** Pero leerlo sí puede cualquiera: es un aviso para todos. */
    public function test_cualquier_rol_puede_leer_el_banner(): void
    {
        $empresa = $this->crearEmpresa();

        foreach (['Compras', 'Calidad', 'Guardia'] as $rol) {
            $usuario = $this->crearUsuarioInterno($empresa, $rol);

            $this->getJson('/api/banner-informativo', $this->cabecerasComo($usuario, $empresa))
                ->assertOk();
        }
    }

    // ---- Audiencia -------------------------------------------------

    /**
     * El filtro de audiencia vive en el BACKEND, no en la pantalla. A
     * quien no le corresponde no se le manda ni el texto ni las URLs de
     * las imágenes: resolverlo del lado del cliente significaría que el
     * contenido igual viajó y basta mirar la respuesta para leerlo.
     */
    public function test_un_aviso_solo_para_internos_no_le_llega_al_proveedor(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [$proveedor] = $this->crearProveedorConUsuario($empresa);
        $cabecerasSistemas = $this->cabecerasComo($sistemas, $empresa);

        $this->post(
            '/api/configuraciones/banner-informativo/piezas',
            ['media' => $this->imagen()],
            $cabecerasSistemas
        )->assertSuccessful();

        $this->encender($cabecerasSistemas, [
            'audiencia' => 'internos',
            'titulo' => 'Reunión de equipo',
        ])->assertOk();

        $respuestaInterno = $this->getJson('/api/banner-informativo', $this->cabecerasComo($compras, $empresa));
        $this->assertTrue($respuestaInterno->json('activo'));
        $this->assertSame('Reunión de equipo', $respuestaInterno->json('titulo'));

        $respuestaProveedor = $this->getJson('/api/banner-informativo', $this->cabecerasComo($proveedor, $empresa));
        $this->assertFalse($respuestaProveedor->json('activo'));
        // Ni el título ni las piezas viajaron.
        $this->assertNull($respuestaProveedor->json('titulo'));
        $this->assertSame([], $respuestaProveedor->json('piezas'));
    }

    public function test_un_aviso_solo_para_proveedores_no_le_llega_al_interno(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [$proveedor] = $this->crearProveedorConUsuario($empresa);
        $cabecerasSistemas = $this->cabecerasComo($sistemas, $empresa);

        $this->encender($cabecerasSistemas, ['audiencia' => 'proveedores'])->assertOk();

        $this->assertTrue(
            $this->getJson('/api/banner-informativo', $this->cabecerasComo($proveedor, $empresa))->json('activo')
        );

        // Cabeceras NUEVAS y no las de arriba: cabecerasComo() es lo que
        // limpia el guard de Sanctum entre peticiones de usuarios
        // distintos (ver Tests\TestCase). Reusando las viejas, esta
        // petición se seguiría viendo como el proveedor.
        $this->assertFalse(
            $this->getJson('/api/banner-informativo', $this->cabecerasComo($sistemas, $empresa))->json('activo')
        );
    }

    public function test_con_audiencia_todos_lo_ven_los_dos(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [$proveedor] = $this->crearProveedorConUsuario($empresa);
        $cabecerasSistemas = $this->cabecerasComo($sistemas, $empresa);

        $this->encender($cabecerasSistemas, ['audiencia' => 'todos'])->assertOk();

        $this->assertTrue($this->getJson('/api/banner-informativo', $cabecerasSistemas)->json('activo'));
        $this->assertTrue(
            $this->getJson('/api/banner-informativo', $this->cabecerasComo($proveedor, $empresa))->json('activo')
        );
    }

    public function test_una_audiencia_inventada_se_rechaza(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $this->putJson('/api/configuraciones/banner-informativo', [
            'activo' => true,
            'audiencia' => 'contadores',
            'frecuencia' => 'una_vez',
        ], $this->cabecerasComo($sistemas, $empresa))->assertStatus(422);
    }

    // ---- Frecuencia --------------------------------------------------

    /**
     * La frecuencia la DECIDE Sistemas y la APLICA el navegador: el
     * backend no puede saber si el token que recibe es de un login nuevo
     * o de una pestaña recargada. Lo que se comprueba acá es que el dato
     * viaje, que es de lo que depende la pantalla.
     */
    public function test_la_frecuencia_viaja_al_navegador(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $this->encender($cabeceras, ['frecuencia' => 'siempre'])->assertOk();
        $this->assertSame('siempre', $this->getJson('/api/banner-informativo', $cabeceras)->json('frecuencia'));

        $this->encender($cabeceras, ['frecuencia' => 'una_vez'])->assertOk();
        $this->assertSame('una_vez', $this->getJson('/api/banner-informativo', $cabeceras)->json('frecuencia'));
    }

    public function test_una_frecuencia_inventada_se_rechaza(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $this->putJson('/api/configuraciones/banner-informativo', [
            'activo' => true,
            'audiencia' => 'todos',
            'frecuencia' => 'cada_hora',
        ], $this->cabecerasComo($sistemas, $empresa))->assertStatus(422);
    }

    /**
     * EL TOPE TIENE QUE SALIR DE php.ini, no de un número escrito a mano.
     *
     * Decía 20 MB mientras PHP aceptaba 10: un archivo de 12 MB no llegaba
     * siquiera a Laravel —PHP lo descarta antes y la petición entra con
     * $_FILES vacío—, así que desde el navegador se veía como una pantalla
     * colgada que después decía "falta el archivo", sin mencionar el
     * tamaño. Pasó de verdad al subir el primer banner.
     */
    public function test_el_tope_de_subida_no_promete_mas_de_lo_que_php_acepta(): void
    {
        $topeDeclarado = BannerInformativoController::maximoKilobytes();

        $limitePhp = min(
            (int) (self::aBytes((string) ini_get('upload_max_filesize')) / 1024),
            (int) (self::aBytes((string) ini_get('post_max_size')) / 1024)
        );

        $this->assertLessThanOrEqual(
            $limitePhp,
            $topeDeclarado,
            'El portal no puede aceptar archivos más grandes de los que PHP deja pasar.'
        );
        $this->assertGreaterThan(0, $topeDeclarado);
    }

    /** Y la pantalla de administración recibe ese número para avisar antes de subir. */
    public function test_la_administracion_informa_el_tope_real(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $maxMb = $this->getJson(
            '/api/configuraciones/banner-informativo',
            $this->cabecerasComo($sistemas, $empresa)
        )->assertOk()->json('max_mb');

        $this->assertIsInt($maxMb);
        $this->assertGreaterThan(0, $maxMb);
    }

    private static function aBytes(string $valor): int
    {
        $numero = (int) $valor;

        return match (strtolower(substr(trim($valor), -1))) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }

    /**
     * EL BUG DE LA PANTALLA GRIS: encendido, pero sin ninguna imagen y sin
     * texto. El modal se dibujaba igual —una tarjeta vacía sobre el fondo
     * oscurecido— y parecía la página colgada. Pasaba al borrar la última
     * pieza desde Configuraciones.
     */
    public function test_encendido_pero_vacio_no_se_le_muestra_a_nadie(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        // Encendido, sin título ni mensaje y sin ninguna pieza.
        $this->encender($cabeceras, ['titulo' => '', 'mensaje' => ''])->assertOk();

        $this->assertFalse(
            $this->getJson('/api/banner-informativo', $cabeceras)->json('activo'),
            'Sin nada que mostrar no puede llegar como activo: deja la pantalla tapada.'
        );
    }

    /** Con texto pero sin imagen sí hay algo que mostrar. */
    public function test_solo_con_texto_si_se_muestra(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $this->encender($cabeceras, ['titulo' => 'Mantenimiento el sábado'])->assertOk();

        $this->assertTrue($this->getJson('/api/banner-informativo', $cabeceras)->json('activo'));
    }

    /** Borrar la única pieza lo apaga de hecho, sin tocar el interruptor. */
    public function test_borrar_la_ultima_pieza_deja_de_mostrarlo(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $idPieza = $this->post(
            '/api/configuraciones/banner-informativo/piezas',
            ['media' => $this->imagen()],
            $cabeceras
        )->assertSuccessful()->json('Id_Banner_Informativo');

        $this->encender($cabeceras, ['titulo' => '', 'mensaje' => ''])->assertOk();
        $this->assertTrue($this->getJson('/api/banner-informativo', $cabeceras)->json('activo'));

        $this->deleteJson("/api/configuraciones/banner-informativo/piezas/{$idPieza}", [], $cabeceras)
            ->assertOk();

        $this->assertFalse($this->getJson('/api/banner-informativo', $cabeceras)->json('activo'));

        // Pero la pantalla de administración sigue viendo el interruptor
        // en su estado real, para poder avisar "encendido pero sin piezas".
        $this->assertTrue(
            $this->getJson('/api/configuraciones/banner-informativo', $cabeceras)->json('activo')
        );
    }

    public function test_una_pieza_sin_archivo_se_rechaza(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $this->postJson(
            '/api/configuraciones/banner-informativo/piezas',
            ['titulo' => 'Sin imagen'],
            $this->cabecerasComo($sistemas, $empresa)
        )->assertStatus(422);
    }
}
