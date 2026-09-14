<?php

namespace Tests\Feature\HorariosEntrega;

use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Services\HorarioEntregaService;
use Tests\TestCase;

/**
 * Lo que habilita los anuncios por voz del Modo TV:
 *
 *  1. /horarios-entrega/hoy?incluir_recibidos=1 devuelve las entregas ya
 *     recibidas (sin el parámetro se siguen ocultando, como siempre). Sin
 *     esto el Modo TV no puede anunciar "ya entregó su pedido": la fila
 *     desaparecía en vez de cambiar de estado.
 *  2. El interruptor global se puede leer desde el Modo TV (cualquier rol
 *     operativo) y solo Sistemas lo puede cambiar.
 */
class AnunciosVozModoTvTest extends TestCase
{
    /** Un horario para HOY, ya marcado como recibido. */
    private function crearHorarioRecibidoDeHoy(int $idEmpresa, int $idProveedor): HorarioEntregaProveedor
    {
        $diaDeHoy = [
            0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles',
            4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado',
        ][now()->dayOfWeek];

        $horario = HorarioEntregaProveedor::create([
            'Id_Empresa' => $idEmpresa,
            'Id_Proveedor' => $idProveedor,
            'Clasificacion' => 'Perecibles',
            'Dia_Entrega' => $diaDeHoy,
            'Anden_Puerta' => 'Andén de prueba',
            'Hora_Llegada' => '06:00',
            'Tiempo_Permanencia_Min' => 60,
            'Hora_Salida' => '07:00',
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        // Hora_Entregado_Real es lo que calcularEstado() lee para devolver
        // 'Recibido' -> es el estado que antes nunca llegaba al frontend.
        HorarioEntregaEstadoDiario::create([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => now()->toDateString(),
            'Hora_Arribo_Real' => now()->subHours(2),
            'Hora_Recepcion_Real' => now()->subHour(),
            'Hora_Entregado_Real' => now()->subMinutes(10),
            'Fecha_Creacion' => now(),
        ]);

        return $horario;
    }

    public function test_sin_el_parametro_la_entrega_recibida_no_aparece(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $horario = $this->crearHorarioRecibidoDeHoy($empresa->Id_Empresa, $proveedor->Id_Proveedor);

        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $respuesta = $this->getJson('/api/horarios-entrega/hoy', $this->cabecerasComo($sistemas, $empresa));

        $respuesta->assertOk();

        $ids = collect($respuesta->json())->pluck('id_horario_entrega_proveedor');
        $this->assertNotContains($horario->Id_Horario_Entrega_Proveedor, $ids->all());
    }

    public function test_con_incluir_recibidos_la_entrega_aparece_con_estado_recibido(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $horario = $this->crearHorarioRecibidoDeHoy($empresa->Id_Empresa, $proveedor->Id_Proveedor);

        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $respuesta = $this->getJson(
            '/api/horarios-entrega/hoy?incluir_recibidos=1',
            $this->cabecerasComo($sistemas, $empresa)
        );

        $respuesta->assertOk();

        $fila = collect($respuesta->json())
            ->firstWhere('id_horario_entrega_proveedor', $horario->Id_Horario_Entrega_Proveedor);

        $this->assertNotNull($fila, 'La entrega recibida debería venir cuando se pide incluir_recibidos.');
        $this->assertSame(HorarioEntregaService::ESTADO_RECIBIDO, $fila['estado']);
        // El nombre es lo que se canta por voz: si viniera null, no habría
        // anuncio posible.
        $this->assertNotNull($fila['nombre_proveedor']);
    }

    public function test_el_guardia_puede_leer_el_interruptor_de_voz(): void
    {
        $empresa = $this->crearEmpresa();
        // El Guardia es quien suele tener la TV delante y NO entra a
        // Configuraciones -> tiene que poder leer el interruptor igual.
        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');

        $this->getJson('/api/horarios-entrega/config-anuncios', $this->cabecerasComo($guardia, $empresa))
            ->assertOk()
            ->assertJsonStructure(['voz_activa']);
    }

    public function test_solo_sistemas_puede_cambiar_el_interruptor(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->putJson(
            '/api/configuraciones/anuncios-voz',
            ['activa' => false],
            $this->cabecerasComo($calidad, $empresa)
        )->assertForbidden();
    }

    public function test_sistemas_apaga_y_el_modo_tv_lo_ve_apagado(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $this->putJson('/api/configuraciones/anuncios-voz', ['activa' => false], $cabeceras)
            ->assertOk()
            ->assertJson(['activa' => false]);

        $this->getJson('/api/horarios-entrega/config-anuncios', $cabeceras)
            ->assertOk()
            ->assertJson(['voz_activa' => false]);
    }
}
