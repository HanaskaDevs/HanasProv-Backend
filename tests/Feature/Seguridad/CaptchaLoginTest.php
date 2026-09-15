<?php

namespace Tests\Feature\Seguridad;

use App\Modules\Auth\Services\TurnstileService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Captcha invisible del login (Cloudflare Turnstile).
 *
 * Lo que estos tests protegen es, sobre todo, que el captcha NO deje a los
 * proveedores sin portal: que se pueda apagar con una variable, y que una
 * caída de Cloudflare no se convierta en una caída del login.
 *
 * Http::fake() en todos: no se le pega a Cloudflare de verdad desde los
 * tests -serían lentos, dependerían de la red y romperían en cualquier
 * máquina sin salida a internet-.
 */
class CaptchaLoginTest extends TestCase
{
    private function encender(array $extra = []): void
    {
        config(array_merge([
            'turnstile.habilitado' => true,
            'turnstile.secret' => 'secret-de-prueba',
            'turnstile.permitir_si_falla' => true,
        ], $extra));
    }

    /** Respuesta con la forma real que devuelve Cloudflare. */
    private function fingirCloudflare(bool $exito, array $extra = []): void
    {
        Http::fake([
            '*challenges.cloudflare.com*' => Http::response(array_merge([
                'success' => $exito,
                'error-codes' => $exito ? [] : ['invalid-input-response'],
                'action' => 'login',
            ], $extra)),
        ]);
    }

    public function test_apagado_el_login_funciona_como_siempre(): void
    {
        config(['turnstile.habilitado' => false]);
        Http::fake();

        // Credenciales inválidas: interesa que el rechazo venga del LOGIN
        // (credenciales) y no del captcha.
        $respuesta = $this->postJson('/api/auth/login', [
            'email' => 'nadie'.uniqid().'@test.local',
            'password' => 'lo-que-sea',
        ]);

        $respuesta->assertStatus(422);
        $this->assertArrayNotHasKey('cf-turnstile-response', (array) $respuesta->json('errors'));

        // Y no se le preguntó nada a Cloudflare.
        Http::assertNothingSent();
    }

    public function test_encendido_sin_token_no_se_llega_a_comprobar_la_contrasena(): void
    {
        $this->encender();
        Http::fake();

        $respuesta = $this->postJson('/api/auth/login', [
            'email' => 'nadie'.uniqid().'@test.local',
            'password' => 'lo-que-sea',
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString(
            'no pudimos verificar',
            mb_strtolower((string) $respuesta->json('message'))
        );

        // Lo importante: sin token ni siquiera se consulta a Cloudflare, y
        // el intento muere antes del Hash::check, que es lo caro y lo que
        // un bot busca agotar.
        Http::assertNothingSent();
    }

    public function test_un_token_que_cloudflare_rechaza_no_deja_entrar(): void
    {
        $this->encender();
        $this->fingirCloudflare(false);

        $this->postJson('/api/auth/login', [
            'email' => 'nadie'.uniqid().'@test.local',
            'password' => 'lo-que-sea',
            'cf-turnstile-response' => 'token-falso',
        ])->assertStatus(422);
    }

    public function test_un_token_de_otra_accion_no_sirve_para_el_login(): void
    {
        $this->encender();
        // Token legítimo, pero obtenido en otra pantalla del sitio.
        $this->fingirCloudflare(true, ['action' => 'contacto']);

        $servicio = app(TurnstileService::class);

        $this->assertFalse($servicio->esValido('token-de-otra-pantalla', '10.0.0.1'));
    }

    public function test_si_cloudflare_se_cae_el_login_sigue_funcionando(): void
    {
        $this->encender();
        // Cloudflare caído: la petición ni siquiera llega.
        Http::fake(fn () => throw new \RuntimeException('connection timed out'));

        $servicio = app(TurnstileService::class);

        // Pasa: un tercero caído no puede dejar sin portal a los proveedores.
        $this->assertTrue($servicio->esValido('token', '10.0.0.1'));
    }

    public function test_se_puede_exigir_el_captcha_aunque_cloudflare_no_responda(): void
    {
        $this->encender(['turnstile.permitir_si_falla' => false]);
        Http::fake(fn () => throw new \RuntimeException('connection timed out'));

        // El comportamiento opuesto también está disponible, por si algún
        // día se prefiere cerrar el login antes que dejar pasar sin verificar.
        $this->assertFalse(app(TurnstileService::class)->esValido('token', '10.0.0.1'));
    }

    public function test_sin_secret_el_captcha_se_considera_apagado(): void
    {
        // Encendido por error pero sin credenciales: en vez de rechazar a
        // todo el mundo, se comporta como si estuviera apagado.
        config(['turnstile.habilitado' => true, 'turnstile.secret' => null]);
        Http::fake();

        $servicio = app(TurnstileService::class);

        $this->assertFalse($servicio->estaActivo());
        $this->assertTrue($servicio->esValido(null));
        Http::assertNothingSent();
    }
}
