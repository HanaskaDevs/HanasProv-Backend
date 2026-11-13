<?php

namespace Tests\Feature\Calidad;

use App\Modules\Auditorias\Notifications\RecepcionesDelDiaNotification;
use App\Modules\Auditorias\Services\AgendaRecepcionService;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Notifications\ArriboProveedorNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Avisos a Calidad (pedidos del 12-nov-2026; destinatarios confirmados el
 * 13-nov: todos los usuarios con rol Calidad):
 *  - cuando el Guardia marca la llegada de un proveedor;
 *  - el aviso de las 5 de la mañana lleva el horario de entrega de cada
 *    proveedor.
 *
 * Vienen de la rama feature/calidad-imagenes-reclamos-arribo (archivo
 * AvisosArriboYHistorialTest), cuyo resto ya está en main por otros commits.
 */
class AvisoArriboCalidadTest extends TestCase
{
    /** El horario de hoy del proveedor, que es lo que marca el Guardia. */
    private function horarioDeHoy(int $idEmpresa, int $idProveedor): HorarioEntregaProveedor
    {
        return HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Id_Proveedor', $idProveedor)
            ->firstOrFail();
    }

    public function test_al_marcar_el_arribo_se_avisa_a_todos_los_usuarios_de_calidad(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');
        $calidad1 = $this->crearUsuarioInterno($empresa, 'Calidad');
        $calidad2 = $this->crearUsuarioInterno($empresa, 'Calidad');
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        // Hora de llegada "ahora" para que el horario no esté ya Rechazado.
        $this->crearEntrega($empresa, $proveedor, horaLlegada: now()->format('H:i'));
        $horario = $this->horarioDeHoy($empresa->Id_Empresa, $proveedor->Id_Proveedor);

        $this->postJson(
            "/api/horarios-entrega/{$horario->Id_Horario_Entrega_Proveedor}/marcar-arribo",
            [],
            $this->cabecerasComo($guardia, $empresa)
        )->assertSuccessful();

        Notification::assertSentTo([$calidad1, $calidad2], ArriboProveedorNotification::class);
        Notification::assertNotSentTo($compras, ArriboProveedorNotification::class);
        Notification::assertNotSentTo($guardia, ArriboProveedorNotification::class);
    }

    public function test_si_no_hay_usuarios_de_calidad_el_arribo_se_marca_igual(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor, horaLlegada: now()->format('H:i'));
        $horario = $this->horarioDeHoy($empresa->Id_Empresa, $proveedor->Id_Proveedor);

        // Lo importante es que el proveedor quede registrado: el correo es
        // secundario y no puede dejar al Guardia sin poder trabajar.
        $this->postJson(
            "/api/horarios-entrega/{$horario->Id_Horario_Entrega_Proveedor}/marcar-arribo",
            [],
            $this->cabecerasComo($guardia, $empresa)
        )->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_el_correo_del_arribo_dice_proveedor_horas_y_quien_lo_marco(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $mail = (new ArriboProveedorNotification('PROVEEDOR DE PRUEBA', '08:30', '08:41', 'Guardia Uno', '3'))
            ->toMail($calidad);
        $html = $mail->render();

        $this->assertStringContainsString('PROVEEDOR DE PRUEBA', $html);
        $this->assertStringContainsString('08:30', $html);
        $this->assertStringContainsString('08:41', $html);
        $this->assertStringContainsString('Guardia Uno', $html);
        // Nada técnico a la vista del usuario (regla del 12-nov).
        $this->assertStringNotContainsString('SIGH', $html);
        $this->assertStringNotContainsString('WMS', $html);
    }

    public function test_el_aviso_de_recepciones_del_dia_lleva_el_horario_de_entrega(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        // Al proveedor le toca recepción hoy y tiene horario cargado.
        $this->crearEntrega($empresa, $proveedor, horaLlegada: '08:30');
        HorarioEntregaProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->update(['Anden_Puerta' => '3']);

        // El aviso solo sale en día laborable (ver esDiaDeAviso): si la
        // suite corre un feriado o un fin de semana, no hay nada que
        // comprobar y la prueba se omite en vez de fallar por el almanaque.
        if (! app(AgendaRecepcionService::class)->esDiaDeAviso(now())) {
            $this->markTestSkipped('Hoy no es día de aviso de recepciones (fin de semana o feriado).');
        }

        $this->artisan('auditorias:avisar-recepciones-del-dia')->assertSuccessful();

        Notification::assertSentTo(
            $calidad,
            RecepcionesDelDiaNotification::class,
            function ($notificacion) use ($calidad, $proveedor) {
                $mail = $notificacion->toMail($calidad);
                $fila = collect($mail->proveedores)->firstWhere('razon_social', $proveedor->Razon_Social);

                return $fila !== null
                    && ! empty($fila['horarios'])
                    && str_contains($fila['horarios'][0], '08:30')
                    && str_contains($fila['horarios'][0], 'andén 3');
            }
        );
    }
}
