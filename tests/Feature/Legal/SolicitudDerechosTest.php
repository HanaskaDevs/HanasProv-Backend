<?php

namespace Tests\Feature\Legal;

use App\Modules\Legal\Mail\SolicitudDerechosMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Formulario de atención de derechos (LOPDP).
 *
 * Lo que importa cubrir acá: que sea accesible SIN sesión (si exigiera
 * login, quien ya no tiene cuenta no podría pedir que borren sus datos), que
 * valide lo que el procedimiento publicado exige, y que el correo salga al
 * Delegado con la dirección del titular como destinatario de respuesta.
 *
 * Mail::fake() en todos los tests: este endpoint manda correo a una casilla
 * real de la empresa, y una suite que la inunde cada vez que corre es
 * inaceptable.
 */
class SolicitudDerechosTest extends TestCase
{
    /** @return array<string, mixed> */
    private function solicitudValida(array $extra = []): array
    {
        return [
            'nombre_completo' => 'María Fernanda Pérez',
            'email' => 'titular@ejemplo.test',
            'cedula' => '1712345678',
            'celular' => '0991234567',
            'derecho' => 'eliminacion',
            'detalle' => 'Solicito la eliminación de mis datos de contacto porque ya no represento a la empresa proveedora.',
            'declaracion' => true,
            ...$extra,
        ];
    }

    public function test_el_formulario_no_exige_sesion(): void
    {
        Mail::fake();

        // Sin cabeceras de autenticación: es el punto del test.
        $this->postJson('/api/derechos-datos', $this->solicitudValida())
            ->assertOk()
            ->assertJsonStructure(['message']);

        Mail::assertQueued(SolicitudDerechosMail::class);
    }

    public function test_el_correo_va_al_delegado_y_responde_al_titular(): void
    {
        Mail::fake();

        $this->postJson('/api/derechos-datos', $this->solicitudValida())->assertOk();

        $delegado = config('portal.proteccion_datos.email');

        Mail::assertQueued(SolicitudDerechosMail::class, function (SolicitudDerechosMail $correo) use ($delegado) {
            // Va al Delegado...
            $llegaAlDelegado = $correo->hasTo($delegado);

            // ...y "Responder" contesta al titular, no al buzón del sistema.
            $sobre = $correo->envelope();
            $respondeAlTitular = collect($sobre->replyTo)
                ->contains(fn ($direccion) => $direccion->address === 'titular@ejemplo.test');

            return $llegaAlDelegado && $respondeAlTitular;
        });
    }

    public function test_el_asunto_nombra_el_derecho_y_al_titular(): void
    {
        Mail::fake();

        $this->postJson('/api/derechos-datos', $this->solicitudValida(['derecho' => 'acceso']))->assertOk();

        Mail::assertQueued(SolicitudDerechosMail::class, function (SolicitudDerechosMail $correo) {
            $asunto = $correo->envelope()->subject;

            return str_contains($asunto, 'Derecho de acceso')
                && str_contains($asunto, 'María Fernanda Pérez');
        });
    }

    /**
     * REGRESIÓN: Mail::fake() NO renderiza la vista, así que un error de
     * sintaxis en la plantilla Blade pasa desapercibido por todos los demás
     * tests y solo explota en producción, al intentar enviar de verdad.
     * Ya pasó una vez con un @if inline. Este test compila la vista.
     */
    public function test_la_plantilla_del_correo_se_renderiza(): void
    {
        $correo = new SolicitudDerechosMail(
            nombreCompleto: 'María Fernanda Pérez',
            email: 'titular@ejemplo.test',
            cedula: '1712345678',
            celular: '0991234567',
            derechoEtiqueta: 'Eliminación',
            detalle: 'Solicito la eliminación de mis datos de contacto.',
            fecha: '25 de agosto de 2026, 10:00',
            ipOrigen: '10.100.60.5',
        );

        $html = $correo->render();

        $this->assertStringContainsString('Solicitud de ejercicio de derechos', $html);
        $this->assertStringContainsString('1712345678', $html);
        $this->assertStringContainsString('15 días', $html);
        $this->assertStringContainsString('IP de origen: 10.100.60.5', $html);
    }

    /** Sin IP no debe quedar el separador suelto colgando del texto. */
    public function test_la_plantilla_se_renderiza_sin_ip(): void
    {
        $correo = new SolicitudDerechosMail(
            nombreCompleto: 'Titular Sin IP',
            email: 'titular@ejemplo.test',
            cedula: '1712345678',
            celular: '0991234567',
            derechoEtiqueta: 'Acceso',
            detalle: 'Solicito copia de mis datos personales.',
            fecha: '25 de agosto de 2026, 10:00',
            ipOrigen: null,
        );

        $html = $correo->render();

        $this->assertStringNotContainsString('IP de origen', $html);
        $this->assertStringContainsString('Portal de', $html);
    }

    public function test_sin_la_declaracion_no_se_envia_nada(): void
    {
        Mail::fake();

        $this->postJson('/api/derechos-datos', $this->solicitudValida(['declaracion' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('declaracion');

        Mail::assertNothingQueued();
    }

    public function test_rechaza_un_derecho_que_no_existe(): void
    {
        Mail::fake();

        $this->postJson('/api/derechos-datos', $this->solicitudValida(['derecho' => 'borrar_todo_el_internet']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('derecho');

        Mail::assertNothingQueued();
    }

    public function test_exige_los_datos_que_acreditan_al_titular(): void
    {
        Mail::fake();

        $this->postJson('/api/derechos-datos', [])
            ->assertStatus(422)
            // Sin estos cuatro no se puede acreditar al titular ni responderle.
            ->assertJsonValidationErrors(['nombre_completo', 'email', 'cedula', 'celular', 'derecho', 'detalle']);

        Mail::assertNothingQueued();
    }

    public function test_un_detalle_de_dos_palabras_no_alcanza(): void
    {
        Mail::fake();

        $this->postJson('/api/derechos-datos', $this->solicitudValida(['detalle' => 'borren todo']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('detalle');
    }

    /**
     * El endpoint es anónimo y dispara correo: sin freno es un relay de spam
     * contra la casilla del Delegado. La ruta declara throttle:3,10.
     */
    public function test_el_throttle_corta_los_envios_repetidos(): void
    {
        Mail::fake();

        for ($intento = 1; $intento <= 3; $intento++) {
            $this->postJson('/api/derechos-datos', $this->solicitudValida())->assertOk();
        }

        $this->postJson('/api/derechos-datos', $this->solicitudValida())
            ->assertStatus(429);

        Mail::assertQueuedCount(3);
    }
}
