<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\CodigoActivacion;
use App\Modules\Auth\Notifications\CodigoActivacionNotification;
use App\Modules\Auth\Services\ResultadoEnvioCodigo;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Cuando alguien de Sistemas manda un código de activación, el portal
 * tiene que decirle si SALIÓ o no, en castellano (02-oct-2026).
 *
 * Antes contestaba siempre "reenviado correctamente": el correo se
 * encolaba y la petición terminaba sin esperar al servidor, así que un
 * rechazo posterior -una dirección mal escrita, el servidor pidiéndonos
 * esperar- quedaba en failed_jobs sin que nadie se enterara, y el usuario
 * seguía sin su código convencido de que se lo habían mandado.
 */
class ResultadoEnvioCodigoTest extends TestCase
{
    private function usuarioSinActivar($empresa)
    {
        return $this->crearUsuarioInterno($empresa, 'Compras', [
            'Requiere_Cambio_Password' => true,
        ]);
    }

    public function test_el_reenvio_responde_con_el_correo_al_que_fue(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $destinatario = $this->usuarioSinActivar($empresa);

        $respuesta = $this->postJson(
            "/api/usuarios/{$destinatario->Id_Usuario}/reenviar-activacion",
            [],
            $this->cabecerasComo($sistemas, $empresa)
        );

        $respuesta->assertOk();
        $this->assertTrue($respuesta->json('enviado'));
        $this->assertSame($destinatario->Email, $respuesta->json('correo'));
        // El mensaje nombra la casilla: es lo que la persona necesita leer
        // para saber a dónde fue.
        $this->assertStringContainsString($destinatario->Email, (string) $respuesta->json('mensaje'));

        Notification::assertSentTo($destinatario, CodigoActivacionNotification::class);
    }

    /**
     * El corazón del cambio: si el servidor de correo rechaza, el portal
     * NO puede contestar "enviado correctamente".
     */
    public function test_si_el_servidor_rechaza_el_portal_lo_dice(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $destinatario = $this->usuarioSinActivar($empresa);

        // Se hace fallar el envío real igual que lo haría el servidor.
        Mail::shouldReceive('mailer')->andThrow(
            new TransportException('550 5.1.1 <'.$destinatario->Email.'>: Recipient address rejected: User unknown')
        );

        $respuesta = $this->postJson(
            "/api/usuarios/{$destinatario->Id_Usuario}/reenviar-activacion",
            [],
            $this->cabecerasComo($sistemas, $empresa)
        );

        // 200 y no 500: no es un error del portal, es información para el
        // usuario. La pantalla la muestra en el modal.
        $respuesta->assertOk();
        $this->assertFalse($respuesta->json('enviado'));
        $this->assertStringContainsString('no existe', (string) $respuesta->json('mensaje'));
        $this->assertNotNull($respuesta->json('sugerencia'));
    }

    /**
     * Y aunque el correo falle, el código queda creado: el alta no se
     * deshace por un correo que no salió, y alcanza con reintentar.
     */
    public function test_aunque_el_correo_falle_el_codigo_queda_generado(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $destinatario = $this->usuarioSinActivar($empresa);

        Mail::shouldReceive('mailer')->andThrow(new TransportException('421 Service not available'));

        $this->postJson(
            "/api/usuarios/{$destinatario->Id_Usuario}/reenviar-activacion",
            [],
            $this->cabecerasComo($sistemas, $empresa)
        )->assertOk();

        $this->assertTrue(
            CodigoActivacion::where('Email', $destinatario->Email)->where('Usado', false)->exists(),
            'El código tiene que existir igual: el usuario no perdió nada.'
        );
    }

    public function test_el_alta_de_un_usuario_devuelve_el_resultado_del_envio(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $correo = 'nuevo_'.Str::lower(Str::random(10)).'@test.local';

        $respuesta = $this->postJson('/api/usuarios/externos', [
            'email' => $correo,
            'id_empresas' => [$empresa->Id_Empresa],
        ], $this->cabecerasComo($sistemas, $empresa));

        $respuesta->assertCreated();
        $respuesta->assertJsonPath('envio.enviado', true);
        $respuesta->assertJsonPath('envio.correo', $correo);
        // El usuario sigue viniendo, ahora anidado.
        $this->assertSame($correo, $respuesta->json('usuario.email'));
    }

    // ---- La traducción, caso por caso -------------------------------

    /** @dataProvider casosDeError */
    public function test_traduce_el_error_a_algo_entendible(string $respuestaSmtp, string $esperado): void
    {
        $resultado = ResultadoEnvioCodigo::fallido('alguien@ejemplo.com', new TransportException($respuestaSmtp));

        $this->assertFalse($resultado->enviado);
        $this->assertStringContainsString($esperado, $resultado->mensaje);

        // Nunca se le muestra jerga al usuario en el mensaje principal: el
        // texto del servidor va aparte, por si Sistemas lo necesita.
        $this->assertStringNotContainsString('SMTP', $resultado->mensaje);
        $this->assertStringNotContainsString('Exception', $resultado->mensaje);
        $this->assertNotNull($resultado->detalleTecnico);
    }

    public static function casosDeError(): array
    {
        return [
            'casilla inexistente' => [
                '550 5.1.1 <x@y.com>: Recipient address rejected: User unknown in virtual mailbox table',
                'no existe',
            ],
            'nos frenaron por volumen' => [
                '450 4.7.1 Error: too much mail from 200.105.224.102',
                'muchos correos seguidos',
            ],
            'no se llega al servidor' => [
                'Connection could not be established with host mail.hanaska.com',
                'No se pudo conectar',
            ],
            'filtro de spam' => [
                '550 5.7.1 Message rejected due to spam content',
                'filtros de seguridad',
            ],
        ];
    }

    /**
     * El caso que más fácil se traduce mal: trae la palabra "denied", que
     * suena a bloqueo, pero el motivo real es el volumen. Si se leyera
     * como bloqueo, el mensaje mandaría a avisar a Sistemas en vez de
     * decir "esperá unos minutos y reintentá".
     */
    public function test_un_limite_que_dice_denied_no_se_lee_como_bloqueo(): void
    {
        $resultado = ResultadoEnvioCodigo::fallido(
            'alguien@ejemplo.com',
            new TransportException('421 4.7.0 Too many messages, access denied, try again later')
        );

        $this->assertStringContainsString('muchos correos seguidos', $resultado->mensaje);
        $this->assertStringContainsString('Espere unos minutos', (string) $resultado->sugerencia);
    }

    public function test_el_mensaje_exitoso_dice_cuanto_dura_el_codigo(): void
    {
        $resultado = ResultadoEnvioCodigo::exitoso('alguien@ejemplo.com', 4320);

        $this->assertTrue($resultado->enviado);
        $this->assertStringContainsString('alguien@ejemplo.com', $resultado->mensaje);
        $this->assertStringContainsString('3 días', (string) $resultado->sugerencia);
    }
}
