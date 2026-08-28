<?php

namespace Tests\Feature\Recepciones;

use App\Modules\Auditorias\Mail\RecepcionSinCalificarMail;
use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Alerta de escalación: el proveedor entregó y pasó el plazo sin que nadie
 * registre la calificación de esa recepción.
 *
 * Lo crítico acá NO es que el correo salga, es que NO salga cuando no
 * corresponde: el comando corre cada hora, así que un falso positivo se
 * convierte en un correo por hora a tres personas.
 *
 * OJO CON LAS ASERCIONES: la base es compartida y tiene entregas REALES de
 * hoy que pueden calificar para la alerta. Por eso NUNCA se usa
 * OJO CON assertQueued vs assertSent: desde que los Mailables implementan
 * ShouldQueue (para que el envío SMTP no bloquee la petición, ver
 * routes/console.php), Mail::fake() los registra como ENCOLADOS y no como
 * enviados -> con assertSent estos tests fallan aunque el correo salga bien.
 *
 * Mail::assertNothingQueued() (afirmar que no salió NINGÚN correo depende de que
 * la base esté vacía, y no lo está): se comprueba que el correo no mencione AL
 * PROVEEDOR DE ESTE TEST. Ya pasó: los tests pasaban en una corrida y fallaban
 * una hora más tarde, cuando una entrega real del día cruzó el plazo.
 */
class AlertaRecepcionSinCalificarTest extends TestCase
{
    private function horarioDe(Proveedor $proveedor, string $dia = 'Miercoles'): HorarioEntregaProveedor
    {
        return HorarioEntregaProveedor::create([
            'Id_Empresa' => $proveedor->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => 'Fruver',
            'Dia_Entrega' => $dia,
            'Anden_Puerta' => 'Puerta A',
            'Hora_Llegada' => '07:00',
            'Activo' => 1,
            'Fecha_Creacion' => now(),
        ]);
    }

    /** Entrega de hoy marcada como entregada hace $horas horas. */
    private function entregaMarcada(HorarioEntregaProveedor $horario, float $horas): HorarioEntregaEstadoDiario
    {
        return HorarioEntregaEstadoDiario::create([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => now()->toDateString(),
            'Hora_Arribo_Real' => now()->subHours($horas + 1)->format('Y-m-d\TH:i:s'),
            'Hora_Entregado_Real' => now()->subHours($horas)->format('Y-m-d\TH:i:s'),
            'Fecha_Creacion' => now(),
        ]);
    }

    private function correr(): void
    {
        $this->artisan('auditorias:avisar-recepciones-sin-calificar')->assertSuccessful();
    }

    /**
     * ¿Algún correo enviado menciona a este proveedor? Es la forma correcta de
     * afirmar "no se avisó de ESTE caso" en una base con datos reales.
     */
    private function seAvisoDe(string $razonSocial): bool
    {
        $encontrado = false;

        Mail::assertQueued(RecepcionSinCalificarMail::class, function (RecepcionSinCalificarMail $correo) use ($razonSocial, &$encontrado) {
            if (collect($correo->casos)->contains(fn ($caso) => $caso['proveedor'] === $razonSocial)) {
                $encontrado = true;
            }

            return true;
        });

        return $encontrado;
    }

    /** Igual que seAvisoDe pero sin exigir que se haya enviado algún correo. */
    private function seAvisoDeSiHubo(string $razonSocial): bool
    {
        try {
            return $this->seAvisoDe($razonSocial);
        } catch (\Throwable) {
            // No se envió ningún correo en absoluto: entonces tampoco de este.
            return false;
        }
    }

    public function test_avisa_cuando_entrego_y_paso_el_plazo_sin_calificar(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => 'PROVEEDOR ALERTA']);

        $this->entregaMarcada($this->horarioDe($proveedor), horas: 2);

        $this->correr();

        Mail::assertQueued(RecepcionSinCalificarMail::class, function (RecepcionSinCalificarMail $correo) {
            return collect($correo->casos)->contains(fn ($caso) => $caso['proveedor'] === 'PROVEEDOR ALERTA');
        });
    }

    /** Recién entregado: el plazo todavía no se cumplió. */
    public function test_no_avisa_antes_de_que_pase_el_plazo(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => 'PROVEEDOR RECIEN ENTREGADO']);

        // 10 minutos: por debajo de la hora de plazo.
        $this->entregaMarcada($this->horarioDe($proveedor), horas: 1 / 6);

        $this->correr();

        $this->assertFalse($this->seAvisoDeSiHubo('PROVEEDOR RECIEN ENTREGADO'));
    }

    /** Arribó pero no se marcó la entrega: todavía está recibiendo. */
    public function test_no_avisa_si_la_entrega_no_esta_marcada(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => 'PROVEEDOR SIN ENTREGA MARCADA']);
        $horario = $this->horarioDe($proveedor);

        HorarioEntregaEstadoDiario::create([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => now()->toDateString(),
            'Hora_Arribo_Real' => now()->subHours(3)->format('Y-m-d\TH:i:s'),
            // Hora_Entregado_Real en null: la entrega no terminó.
            'Fecha_Creacion' => now(),
        ]);

        $this->correr();

        $this->assertFalse($this->seAvisoDeSiHubo('PROVEEDOR SIN ENTREGA MARCADA'));
    }

    /** Ya hay calificación registrada hoy: nada que escalar. */
    public function test_no_avisa_si_ya_se_registro_la_calificacion_de_hoy(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => 'PROVEEDOR YA CALIFICADO']);
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->entregaMarcada($this->horarioDe($proveedor), horas: 3);

        CalificacionRecepcion::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Usuario_Auditor' => $calidad->Id_Usuario,
            'Fecha_Recepcion' => now()->toDateString(),
            'Estado' => 'Borrador',
            'Fecha_Creacion' => now(),
        ]);

        $this->correr();

        $this->assertFalse($this->seAvisoDeSiHubo('PROVEEDOR YA CALIFICADO'));
    }

    /**
     * El comando corre cada hora: el mismo caso NO puede volver a
     * reportarse en la corrida siguiente.
     */
    public function test_el_mismo_caso_no_se_reporta_dos_veces(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => 'PROVEEDOR REPETIDO']);

        $entrega = $this->entregaMarcada($this->horarioDe($proveedor), horas: 2);

        $this->correr();
        $this->assertTrue($this->seAvisoDe('PROVEEDOR REPETIDO'), 'La primera corrida tiene que avisar.');

        // Segunda corrida: este caso ya está marcado y NO debe volver a
        // aparecer. Se cuenta en cuántos correos apareció.
        $this->correr();

        $veces = 0;
        Mail::assertQueued(RecepcionSinCalificarMail::class, function (RecepcionSinCalificarMail $correo) use (&$veces) {
            if (collect($correo->casos)->contains(fn ($c) => $c['proveedor'] === 'PROVEEDOR REPETIDO')) {
                $veces++;
            }

            return true;
        });

        $this->assertSame(1, $veces, 'El mismo caso no puede reportarse dos veces.');

        $this->assertNotNull(
            $entrega->fresh()->Alerta_Sin_Calificacion_Enviada,
            'La fila tiene que quedar marcada para no repetir el aviso.'
        );
    }

    /** Varios casos del día entran en un solo correo, no uno por proveedor. */
    public function test_agrupa_todos_los_casos_en_un_solo_correo(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();

        foreach (range(1, 3) as $i) {
            [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => "PROVEEDOR AGRUPADO {$i}"]);
            $this->entregaMarcada($this->horarioDe($proveedor), horas: 2);
        }

        $this->correr();

        // Un solo correo por corrida, con los tres casos dentro. Puede traer
        // además casos reales de la base, así que se comprueba que los TRES
        // propios estén en el MISMO correo, no que el correo tenga 3 casos.
        Mail::assertQueuedCount(1);
        Mail::assertQueued(RecepcionSinCalificarMail::class, function (RecepcionSinCalificarMail $correo) {
            $nombres = collect($correo->casos)->pluck('proveedor');

            return $nombres->contains('PROVEEDOR AGRUPADO 1')
                && $nombres->contains('PROVEEDOR AGRUPADO 2')
                && $nombres->contains('PROVEEDOR AGRUPADO 3');
        });
    }

    public function test_va_a_los_destinatarios_configurados(): void
    {
        Mail::fake();

        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->entregaMarcada($this->horarioDe($proveedor), horas: 2);

        $this->correr();

        $esperados = (array) config('portal.alertas_recepcion_sin_calificar');
        $this->assertNotEmpty($esperados, 'La configuración de destinatarios no puede estar vacía.');

        Mail::assertQueued(RecepcionSinCalificarMail::class, function (RecepcionSinCalificarMail $correo) use ($esperados) {
            foreach ($esperados as $destinatario) {
                if (! $correo->hasTo($destinatario)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * REGRESIÓN: Mail::fake() no renderiza la vista, así que un error de
     * Blade en la plantilla pasaría desapercibido y solo explotaría al
     * enviar de verdad.
     */
    public function test_la_plantilla_del_correo_se_renderiza(): void
    {
        $correo = new RecepcionSinCalificarMail(
            casos: [
                ['proveedor' => 'PROVEEDOR UNO', 'ruc' => '1792347726001', 'empresa' => 'Caterfood', 'hora_entrega' => '08:15', 'anden' => 'Puerta A'],
                ['proveedor' => 'PROVEEDOR DOS', 'ruc' => null, 'empresa' => 'Caterfood', 'hora_entrega' => '10:40', 'anden' => null],
            ],
            fecha: 'miércoles 26 de agosto de 2026',
            horasDePlazo: 1,
        );

        $html = $correo->render();

        $this->assertStringContainsString('PROVEEDOR UNO', $html);
        $this->assertStringContainsString('1792347726001', $html);
        $this->assertStringContainsString('PROVEEDOR DOS', $html);
        $this->assertStringContainsString('más de una hora', $html);
        $this->assertStringContainsString('2 recepciones quedaron sin calificar', $html);
    }
}
