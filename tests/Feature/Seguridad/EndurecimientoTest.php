<?php

namespace Tests\Feature\Seguridad;

use App\Modules\Auth\Models\CodigoActivacion;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Modules\Auth\Models\Sesion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * El resto del endurecimiento de seguridad del 28-ago-2026: no revelar qué
 * correos existen, exigir contraseñas decentes, y que una sesión vencida
 * deje de servir de verdad.
 */
class EndurecimientoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
        Mail::fake();
    }

    // ---------------------------------------------------------------
    // Enumeración de usuarios
    // ---------------------------------------------------------------

    /**
     * El punto entero: las dos respuestas tienen que ser INDISTINGUIBLES.
     * Si difieren en algo (código o texto), sirven para averiguar qué
     * correos están registrados en el portal.
     */
    public function test_olvide_password_responde_igual_exista_o_no_el_correo(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        $existente = $this->postJson('/api/auth/olvide-password', ['email' => $usuario->Email]);
        Cache::flush(); // el throttle de este endpoint es 3 cada 10 min
        $inexistente = $this->postJson('/api/auth/olvide-password', ['email' => 'nadie_zzz@nada.local']);

        $existente->assertOk();
        $inexistente->assertOk();
        $this->assertSame($existente->json('message'), $inexistente->json('message'));
    }

    public function test_olvide_password_no_manda_codigo_a_un_correo_que_no_existe(): void
    {
        $this->postJson('/api/auth/olvide-password', ['email' => 'nadie_zzz@nada.local'])->assertOk();

        $this->assertSame(0, CodigoActivacion::where('Email', 'nadie_zzz@nada.local')->count());
        Notification::assertNothingSent();
    }

    public function test_activar_cuenta_no_delata_si_el_correo_existe(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        $conCuenta = $this->postJson('/api/auth/activar-cuenta', [
            'email' => $usuario->Email,
            'codigo' => 'XXXX-0000',
            'password_nueva' => 'Segura#2026',
            'password_nueva_confirmation' => 'Segura#2026',
        ]);
        Cache::flush();
        $sinCuenta = $this->postJson('/api/auth/activar-cuenta', [
            'email' => 'nadie_zzz@nada.local',
            'codigo' => 'XXXX-0000',
            'password_nueva' => 'Segura#2026',
            'password_nueva_confirmation' => 'Segura#2026',
        ]);

        $conCuenta->assertStatus(422);
        $sinCuenta->assertStatus(422);
        $this->assertSame(
            $conCuenta->json('errors'),
            $sinCuenta->json('errors'),
            'Un correo registrado y uno que no existe deben dar exactamente el mismo error.'
        );
    }

    // ---------------------------------------------------------------
    // Contraseñas
    // ---------------------------------------------------------------

    public static function contrasenasDebiles(): array
    {
        return [
            'solo letras' => ['contrasena'],
            'solo numeros' => ['12345678'],
            'sin caracter especial' => ['contrasena123'],
            'con especial pero sin numero' => ['contrasena#'],
            'demasiado corta' => ['Ab1#xy'],
        ];
    }

    #[DataProvider('contrasenasDebiles')]
    public function test_rechaza_contrasenas_debiles(string $password): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        $this->postJson('/api/auth/cambiar-password', [
            'password_actual' => 'secreto123',
            'password_nueva' => $password,
            'password_nueva_confirmation' => $password,
        ], $this->cabecerasComo($usuario, $empresa))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password_nueva');
    }

    public function test_acepta_una_contrasena_que_cumple_la_politica(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        $this->postJson('/api/auth/cambiar-password', [
            'password_actual' => 'secreto123',
            'password_nueva' => 'Segura#2026',
            'password_nueva_confirmation' => 'Segura#2026',
        ], $this->cabecerasComo($usuario, $empresa))
            ->assertOk();
    }

    /**
     * La política es SOLO para contraseñas nuevas. Un usuario que ya existe
     * con una clave que no la cumple ('secreto123' no tiene símbolo) tiene
     * que poder seguir entrando igual -> si el login la validara, el cambio
     * dejaría afuera a todos los usuarios actuales de un día para el otro.
     */
    public function test_los_usuarios_existentes_siguen_entrando_con_su_clave_vieja(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        $this->postJson('/api/auth/login', [
            'email' => $usuario->Email,
            'password' => 'secreto123',
        ])->assertOk();
    }

    /**
     * Guarda contra una regresión concreta: la carpeta lang/ NO existía y,
     * con APP_LOCALE=es, Laravel devolvía la CLAVE en vez del mensaje. Al
     * usuario le llegaba literalmente "validation.email" o
     * "validation.password.symbols" en pantalla.
     *
     * Si alguien borra lang/es/validation.php, este test lo detecta.
     */
    public function test_los_errores_de_validacion_salen_en_espaniol(): void
    {
        $respuesta = $this->postJson('/api/auth/login', ['email' => 'no-es-un-correo']);

        $respuesta->assertStatus(422);

        foreach (['email', 'password'] as $campo) {
            $mensaje = $respuesta->json("errors.{$campo}.0");

            $this->assertStringNotContainsString(
                'validation.',
                $mensaje,
                "El error de '{$campo}' está devolviendo la clave interna en vez del mensaje traducido."
            );
        }
    }

    public function test_el_error_de_contrasenia_explica_el_formato(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        $respuesta = $this->postJson('/api/auth/cambiar-password', [
            'password_actual' => 'secreto123',
            'password_nueva' => '12345678',
            'password_nueva_confirmation' => '12345678',
        ], $this->cabecerasComo($usuario, $empresa));

        $mensaje = $respuesta->json('errors.password_nueva.0');

        $this->assertStringContainsString('formato de la contraseña', $mensaje);
        $this->assertStringContainsString('carácter especial', $mensaje);
    }

    // ---------------------------------------------------------------
    // Vigencia de la sesión
    // ---------------------------------------------------------------

    public function test_una_sesion_vencida_ya_no_sirve(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);
        $cabeceras = $this->cabecerasComo($usuario, $empresa);

        // Con la sesión vigente entra.
        $this->getJson('/api/empresas', $cabeceras)->assertOk();

        // Se la vence a mano, como si hubieran pasado las 24 h.
        Sesion::where('Id_Usuario', $usuario->Id_Usuario)
            ->update(['Fecha_Expiracion' => now()->subMinute()->format('Y-m-d\TH:i:s')]);

        // Antes esto seguía funcionando: Fecha_Expiracion se escribía pero
        // nunca se miraba, así que un token robado no caducaba jamás.
        $this->getJson('/api/empresas', $cabeceras)->assertStatus(401);
    }

    public function test_el_login_deja_la_sesion_con_24_horas_de_vigencia(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        $this->postJson('/api/auth/login', [
            'email' => $usuario->Email,
            'password' => 'secreto123',
        ])->assertOk();

        $sesion = Sesion::where('Id_Usuario', $usuario->Id_Usuario)->latest('Id_Sesion')->first();

        $horas = now()->diffInHours($sesion->Fecha_Expiracion, absolute: true);
        $this->assertEqualsWithDelta(24, $horas, 1);
    }
}
