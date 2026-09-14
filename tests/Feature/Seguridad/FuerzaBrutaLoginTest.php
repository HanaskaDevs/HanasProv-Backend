<?php

namespace Tests\Feature\Seguridad;

use App\Modules\Auth\Models\BitacoraAcceso;
use App\Modules\Auth\Models\IpBloqueada;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Defensa contra fuerza bruta en el login, en sus dos formas: bloqueo de la
 * CUENTA a los 3 fallos, y bloqueo de la IP a los 5 correos inexistentes.
 */
class FuerzaBrutaLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El throttle de las rutas (throttle:10,1) guarda su conteo en caché,
        // y con el store 'array' de los tests ese conteo sobrevive de un test
        // al siguiente dentro del mismo proceso -> sin este flush, el tercer
        // test de la clase empieza con el cupo ya gastado y recibe 429 en vez
        // de lo que está probando.
        Cache::flush();

        // Ningún test de acá debe mandar correos de verdad.
        Notification::fake();
        Mail::fake();
    }

    private function intentarLogin(string $email, string $password = 'clave-incorrecta')
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    // ---------------------------------------------------------------
    // Bloqueo de la cuenta
    // ---------------------------------------------------------------

    public function test_tres_fallos_seguidos_bloquean_la_cuenta(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        for ($i = 1; $i <= 3; $i++) {
            $this->intentarLogin($usuario->Email)->assertStatus(422);
        }

        $this->assertTrue((bool) $usuario->fresh()->Bloqueado_Por_Intentos);
    }

    public function test_dos_fallos_todavia_no_bloquean(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        $this->intentarLogin($usuario->Email)->assertStatus(422);
        $this->intentarLogin($usuario->Email)->assertStatus(422);

        $this->assertFalse((bool) $usuario->fresh()->Bloqueado_Por_Intentos);
    }

    public function test_la_cuenta_bloqueada_no_entra_ni_con_la_clave_correcta(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        for ($i = 1; $i <= 3; $i++) {
            $this->intentarLogin($usuario->Email);
        }

        // Ésta es la clave BUENA (ver TestCase::crearUsuarioInterno).
        $respuesta = $this->intentarLogin($usuario->Email, 'secreto123');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString(
            'bloqueada',
            $respuesta->json('errors.email.0'),
            'Debe explicarle que está bloqueada, no repetir "credenciales no válidas".'
        );
    }

    public function test_entrar_bien_reinicia_el_contador_de_fallos(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        // 2 fallos, después entra bien, después 2 fallos más: son 4 fallos
        // en total pero solo 2 SEGUIDOS -> no debe quedar bloqueada.
        $this->intentarLogin($usuario->Email);
        $this->intentarLogin($usuario->Email);
        $this->intentarLogin($usuario->Email, 'secreto123')->assertOk();
        $this->intentarLogin($usuario->Email);
        $this->intentarLogin($usuario->Email);

        $this->assertFalse((bool) $usuario->fresh()->Bloqueado_Por_Intentos);
    }

    public function test_al_bloquear_se_cierran_las_sesiones_abiertas(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        // Deja una sesión viva, como si el atacante ya hubiera entrado.
        $this->cabecerasComo($usuario, $empresa);
        $this->assertSame(1, $usuario->tokens()->count());

        for ($i = 1; $i <= 3; $i++) {
            $this->intentarLogin($usuario->Email);
        }

        $this->assertSame(0, $usuario->fresh()->tokens()->count());
    }

    // ---------------------------------------------------------------
    // Bloqueo de la IP
    // ---------------------------------------------------------------

    public function test_cinco_correos_inexistentes_seguidos_bloquean_la_ip(): void
    {
        for ($i = 1; $i <= AuthService::MAX_INTENTOS_IP_INEXISTENTES; $i++) {
            $this->intentarLogin("fantasma_{$i}@nadie.local")->assertStatus(422);
        }

        $this->assertTrue(IpBloqueada::estaBloqueada('127.0.0.1'));
    }

    public function test_la_racha_se_corta_si_el_correo_si_existe(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        // 4 inexistentes, uno real en el medio, y otro inexistente: nunca
        // hay 5 seguidos, así que la IP no se bloquea.
        for ($i = 1; $i <= 4; $i++) {
            $this->intentarLogin("fantasma_{$i}@nadie.local");
        }
        $this->intentarLogin($usuario->Email);
        $this->intentarLogin('fantasma_5@nadie.local');

        $this->assertFalse(IpBloqueada::estaBloqueada('127.0.0.1'));
    }

    public function test_la_ip_bloqueada_no_entra_ni_con_credenciales_validas(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa);

        for ($i = 1; $i <= AuthService::MAX_INTENTOS_IP_INEXISTENTES; $i++) {
            $this->intentarLogin("fantasma_{$i}@nadie.local");
        }

        $respuesta = $this->intentarLogin($usuario->Email, 'secreto123');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('red', $respuesta->json('errors.email.0'));
    }

    public function test_el_bloqueo_de_ip_queda_registrado_en_la_bitacora(): void
    {
        for ($i = 1; $i <= AuthService::MAX_INTENTOS_IP_INEXISTENTES; $i++) {
            $this->intentarLogin("fantasma_{$i}@nadie.local");
        }

        $this->assertTrue(
            BitacoraAcceso::where('Tipo_Evento', AuthService::EVENTO_IP_BLOQUEADA)
                ->where('Ip_Origen', '127.0.0.1')
                ->exists()
        );
    }

    // ---------------------------------------------------------------
    // Reactivación (solo Sistemas)
    // ---------------------------------------------------------------

    public function test_sistemas_reactiva_y_se_le_manda_codigo_nuevo(): void
    {
        $empresa = $this->crearEmpresa();
        $victima = $this->crearUsuarioInterno($empresa, 'Compras');
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        for ($i = 1; $i <= 3; $i++) {
            $this->intentarLogin($victima->Email);
        }
        $this->assertTrue((bool) $victima->fresh()->Bloqueado_Por_Intentos);

        $this->patchJson(
            "/api/usuarios/{$victima->Id_Usuario}/reactivar",
            [],
            $this->cabecerasComo($sistemas, $empresa)
        )->assertOk();

        $victima = $victima->fresh();
        $this->assertFalse((bool) $victima->Bloqueado_Por_Intentos);
        $this->assertTrue((bool) $victima->Activo);

        // Tiene que definir una contraseña nueva -> le llega un código.
        Notification::assertSentTo($victima, \App\Modules\Auth\Notifications\CodigoActivacionNotification::class);
    }

    public function test_un_admin_no_puede_reactivar(): void
    {
        $empresa = $this->crearEmpresa();
        $victima = $this->crearUsuarioInterno($empresa, 'Compras');
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');

        $this->patchJson(
            "/api/usuarios/{$victima->Id_Usuario}/reactivar",
            [],
            $this->cabecerasComo($admin, $empresa)
        )->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Bandeja de IP bloqueadas
    // ---------------------------------------------------------------

    /**
     * OJO: un rol por test, y no los dos encadenados en el mismo.
     * Laravel resuelve el usuario autenticado UNA vez por test y lo
     * conserva entre llamadas, así que pegarle primero como Compras y
     * después como Sistemas devolvía 403 las dos veces -> parecía un fallo
     * de permisos cuando en realidad la segunda petición seguía viajando
     * como el primer usuario.
     */
    public function test_un_rol_que_no_es_sistemas_no_ve_las_ip_bloqueadas(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->getJson('/api/auth/ips-bloqueadas', $this->cabecerasComo($compras, $empresa))
            ->assertForbidden();
    }

    public function test_sistemas_lista_y_libera_una_ip_bloqueada(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $ip = IpBloqueada::create([
            'Ip' => '203.0.113.9',
            'Motivo' => 'prueba',
            'Intentos' => 5,
            'Fecha_Bloqueo' => now(),
            'Activa' => true,
        ]);

        $this->getJson('/api/auth/ips-bloqueadas', $cabeceras)->assertOk();

        $this->postJson("/api/auth/ips-bloqueadas/{$ip->Id_Ip_Bloqueada}/desbloquear", [], $cabeceras)
            ->assertOk();

        // No se borra la fila: queda el histórico de que esa IP atacó.
        $ip = $ip->fresh();
        $this->assertFalse((bool) $ip->Activa);
        $this->assertSame($sistemas->Id_Usuario, (int) $ip->Desbloqueada_Por);
        $this->assertFalse(IpBloqueada::estaBloqueada('203.0.113.9'));
    }
}
