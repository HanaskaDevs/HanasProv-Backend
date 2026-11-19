<?php

namespace Tests\Feature\HorariosEntrega;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Http\Middleware\TokenIntegracionBc;
use App\Modules\Horarios_Entrega\Models\HistorialHorarioProveedor;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Services\FueraPlanificacionService;
use App\Modules\Horarios_Entrega\Services\HistorialHorarioProveedorService as Historial;
use App\Modules\Horarios_Entrega\Services\HorarioEntregaService;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Pedidos fuera de planificación (19-nov-2026): turnos sueltos agendados en
 * BC dentro de ventanas fijas, de 20/40/60 min, uno a la vez por
 * clasificación, 10 min de tolerancia para el Guardia y sin aprobación.
 *
 * Todo pasa en una semana de 2099 (domingo 01-mar-2099 01:00 a domingo
 * 08-mar 01:00) y con una empresa propia: no se cruza con datos reales.
 * DatabaseTransactions deshace todo al terminar.
 */
class FueraPlanificacionTest extends TestCase
{
    private const URL = '/api/integraciones/bc/fuera-planificacion';

    private const TOKEN = 'token-de-prueba-bc-fuera-plan-0123456789';

    // Semana de prueba: el martes 03-mar-2099 a las 10:00.
    private const LUNES = '2099-03-02';
    private const MARTES = '2099-03-03';
    private const JUEVES = '2099-03-05';
    private const VIERNES = '2099-03-06';
    private const SABADO = '2099-03-07';

    private Empresa $empresa;

    private Usuario $sistemas;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'portal.minutos_atrasado_a_rechazado' => 30,
            'portal.fuera_planificacion.tolerancia_minutos' => 10,
        ]);

        $this->empresa = $this->crearEmpresa(['Empresa_BC' => 'fp_'.Str::lower(Str::random(10))]);
        $this->sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        config(['integraciones.bc.token' => self::TOKEN, 'integraciones.bc.usuario' => $this->sistemas->Email]);

        Carbon::setTestNow(self::MARTES.' 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servicio(): FueraPlanificacionService
    {
        return app(FueraPlanificacionService::class);
    }

    /** Proveedor del portal con su código BC. */
    private function proveedor(?string $codigo = null): array
    {
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($this->empresa);
        $codigo ??= 'FP'.random_int(100000, 999999);
        Proveedor::whereKey($proveedor->Id_Proveedor)->update(['Nro_Proveedor_BC' => $codigo]);

        return [$usuario, $proveedor->fresh(), $codigo];
    }

    private function turno(int $idBc, string $codigo, array $extra = []): array
    {
        return [
            'id_bc' => $idBc,
            'clasificacion' => 'Perecibles',
            'dia_entrega' => 'Jueves',
            'fecha' => self::JUEVES,
            'codigo_bc' => $codigo,
            'anden_puerta' => 'a',
            'hora_llegada' => '15:00',
            'duracion_min' => 20,
            ...$extra,
        ];
    }

    private function enviar(array $turnos, bool $confirmar = true)
    {
        return $this->postJson(self::URL, [
            'empresa_bc' => trim($this->empresa->Empresa_BC),
            'confirmar' => $confirmar,
            'turnos' => $turnos,
        ], [TokenIntegracionBc::CABECERA => self::TOKEN]);
    }

    private function delPortal(int $idBc): ?HorarioEntregaProveedor
    {
        return HorarioEntregaProveedor::where('Id_Empresa', $this->empresa->Id_Empresa)->where('Id_Turno_Bc', $idBc)->first();
    }

    // ------------------------------------------------------------------
    // Semana, ventanas y duración
    // ------------------------------------------------------------------

    public function test_la_semana_empieza_el_domingo_a_la_una(): void
    {
        $s = $this->servicio();

        $this->assertSame('2099-03-01 01:00', $s->inicioDelCiclo(Carbon::parse(self::MARTES.' 10:00'))->format('Y-m-d H:i'));
        // Domingo antes de la 1: todavía es la semana anterior (BC no borró).
        $this->assertSame('2099-02-22 01:00', $s->inicioDelCiclo(Carbon::parse('2099-03-01 00:59'))->format('Y-m-d H:i'));
        $this->assertSame('2099-03-01 01:00', $s->inicioDelCiclo(Carbon::parse('2099-03-01 01:00'))->format('Y-m-d H:i'));
        $this->assertSame('2099-03-01 01:00', $s->inicioDelCiclo(Carbon::parse('2099-03-07 23:59'))->format('Y-m-d H:i'));

        $this->assertSame(self::LUNES, $s->fechaDelDia('Lunes')->toDateString());
        $this->assertSame(self::SABADO, $s->fechaDelDia('Sabado')->toDateString());
        // El domingo a las 10:00 "Lunes" ya es el de mañana.
        $this->assertSame('2099-03-09', $s->fechaDelDia('Lunes', Carbon::parse('2099-03-08 10:00'))->toDateString());
    }

    public function test_ventanas_duracion_y_hora_de_cierre(): void
    {
        $s = $this->servicio();

        // Caben justo: terminan a la hora de cierre.
        $this->assertNull($s->motivoFueraDeVentana('Perecibles', 'Jueves', '16:40', 20));
        $this->assertNull($s->motivoFueraDeVentana('Perecibles', 'Jueves', '15:00', 60));
        $this->assertNull($s->motivoFueraDeVentana('No_Perecibles', 'Viernes', '15:00', 60));
        $this->assertNull($s->motivoFueraDeVentana('Fruver', 'Sabado', '15:20', 40));

        // El ejemplo del pedido: cierra a las 16:00, a las 15:50 no cabe uno de 20.
        $this->assertStringContainsString('después del cierre de las 16:00', $s->motivoFueraDeVentana('No_Perecibles', 'Viernes', '15:50', 20));
        $this->assertNotNull($s->motivoFueraDeVentana('Perecibles', 'Jueves', '16:50', 20));
        $this->assertNotNull($s->motivoFueraDeVentana('Fruver', 'Lunes', '14:30', 60));

        // Antes de abrir.
        $this->assertStringContainsString('abre a las 15:20', $s->motivoFueraDeVentana('No_Perecibles', 'Jueves', '15:00', 20));

        // Día sin ventana.
        $this->assertStringContainsString('no recibe', $s->motivoFueraDeVentana('Fruver', 'Viernes', '14:00', 20));
        $this->assertStringContainsString('no recibe', $s->motivoFueraDeVentana('Perecibles', 'Lunes', '15:00', 20));
        $this->assertStringContainsString('no recibe', $s->motivoFueraDeVentana('Fruver', 'Domingo', '15:00', 20));

        // Solo 20, 40 o 60.
        foreach ([0, 10, 30, 45, 90] as $duracion) {
            $this->assertStringContainsString('20, 40, 60', $s->motivoFueraDeVentana('Perecibles', 'Jueves', '15:00', $duracion));
        }
    }

    // ------------------------------------------------------------------
    // Sincronización desde BC
    // ------------------------------------------------------------------

    public function test_crea_el_turno_con_su_fecha_y_salida_y_reenviarlo_no_lo_duplica(): void
    {
        [, $proveedor, $codigo] = $this->proveedor();

        $this->enviar([$this->turno(101, $codigo, ['hora_llegada' => '15:20', 'duracion_min' => 40])])
            ->assertOk()
            ->assertJsonPath('resumen.crear', 1)
            ->assertJsonPath('turnos.0.resultado', 'crear')
            ->assertJsonPath('turnos.0.fecha', self::JUEVES)
            ->assertJsonPath('turnos.0.hora_salida', '16:00');

        $h = $this->delPortal(101);
        $this->assertNotNull($h);
        $this->assertSame($proveedor->Id_Proveedor, (int) $h->Id_Proveedor);
        $this->assertSame(self::JUEVES, $h->Fecha_Fuera_Planificacion->toDateString());
        $this->assertSame('Jueves', $h->Dia_Entrega);
        $this->assertSame('A', $h->Anden_Puerta);
        $this->assertSame('15:20', $h->Hora_Llegada);
        $this->assertSame(40, $h->Tiempo_Permanencia_Min);
        $this->assertSame('16:00', $h->Hora_Salida);
        $this->assertTrue($h->Activo);
        $this->assertSame($this->sistemas->Id_Usuario, (int) $h->Creado_Por);

        // Mismo envío otra vez: sin cambio, sin duplicar.
        $this->enviar([$this->turno(101, $codigo, ['hora_llegada' => '15:20', 'duracion_min' => 40])])
            ->assertOk()
            ->assertJsonPath('resumen.sin_cambio', 1)
            ->assertJsonPath('resumen.crear', 0);
        $this->assertSame(1, HorarioEntregaProveedor::where('Id_Empresa', $this->empresa->Id_Empresa)->count());

        // Cambio de hora: se actualiza el mismo.
        $this->enviar([$this->turno(101, $codigo, ['hora_llegada' => '16:00', 'duracion_min' => 60])])
            ->assertOk()
            ->assertJsonPath('resumen.actualizar', 1);
        $this->assertSame('17:00', $this->delPortal(101)->Hora_Salida);
        $this->assertSame(1, HorarioEntregaProveedor::where('Id_Empresa', $this->empresa->Id_Empresa)->count());
    }

    public function test_vista_previa_no_escribe(): void
    {
        [, , $codigo] = $this->proveedor();

        $this->enviar([$this->turno(102, $codigo)], confirmar: false)
            ->assertOk()
            ->assertJsonPath('resumen.crear', 1);

        $this->assertNull($this->delPortal(102));
    }

    public function test_un_turno_a_la_vez_por_clasificacion(): void
    {
        [, , $codigoA] = $this->proveedor();
        [, , $codigoB] = $this->proveedor();
        [, , $codigoC] = $this->proveedor();

        $respuesta = $this->enviar([
            $this->turno(201, $codigoA, ['hora_llegada' => '15:00', 'duracion_min' => 40]),          // 15:00-15:40
            $this->turno(202, $codigoB, ['hora_llegada' => '15:20', 'duracion_min' => 20, 'anden_puerta' => 'B']), // se cruza (otro andén igual)
            $this->turno(203, $codigoC, ['hora_llegada' => '15:40', 'duracion_min' => 20]),          // empieza justo al terminar el primero
            // Otra clasificación a la misma hora: no se cruza.
            $this->turno(204, $codigoB, ['clasificacion' => 'Fruver', 'hora_llegada' => '14:00', 'duracion_min' => 60]),
            $this->turno(205, $codigoC, ['clasificacion' => 'No_Perecibles', 'hora_llegada' => '15:20', 'duracion_min' => 20]),
        ]);

        $respuesta->assertOk()
            ->assertJsonPath('resumen.crear', 4)
            ->assertJsonPath('resumen.error', 1)
            ->assertJsonPath('turnos.1.resultado', 'error');
        $this->assertStringContainsString('un turno a la vez', $respuesta->json('turnos.1.mensaje'));
        $this->assertStringContainsString('15:00 a 15:40', $respuesta->json('turnos.1.mensaje'));
        $this->assertNull($this->delPortal(202));

        // El que ya estaba gana aunque el nuevo empiece antes.
        $this->enviar([
            $this->turno(201, $codigoA, ['hora_llegada' => '15:00', 'duracion_min' => 40]),
            $this->turno(203, $codigoC, ['hora_llegada' => '15:40', 'duracion_min' => 20]),
            $this->turno(204, $codigoB, ['clasificacion' => 'Fruver', 'hora_llegada' => '14:00', 'duracion_min' => 60]),
            $this->turno(205, $codigoC, ['clasificacion' => 'No_Perecibles', 'hora_llegada' => '15:20', 'duracion_min' => 20]),
            $this->turno(206, $codigoB, ['clasificacion' => 'No_Perecibles', 'hora_llegada' => '15:20', 'duracion_min' => 20]),
        ])->assertOk()
            ->assertJsonPath('turnos.4.resultado', 'error')
            ->assertJsonPath('turnos.3.resultado', 'sin_cambio');
    }

    public function test_el_calendario_semanal_no_cuenta_para_el_cruce(): void
    {
        [, $proveedor, $codigo] = $this->proveedor();

        // Horario semanal de Perecibles el jueves 15:00-16:00.
        HorarioEntregaProveedor::create([
            'Id_Empresa' => $this->empresa->Id_Empresa, 'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => 'Perecibles', 'Dia_Entrega' => 'Jueves', 'Hora_Llegada' => '15:00',
            'Tiempo_Permanencia_Min' => 60, 'Hora_Salida' => '16:00', 'Activo' => 1,
        ]);

        $this->enviar([$this->turno(301, $codigo, ['hora_llegada' => '15:00'])])
            ->assertOk()->assertJsonPath('resumen.crear', 1);
    }

    public function test_rechaza_lo_que_no_corresponde_y_aplica_el_resto(): void
    {
        [, , $codigo] = $this->proveedor();

        $respuesta = $this->enviar([
            $this->turno(401, $codigo),                                                                 // ok
            $this->turno(402, $codigo, ['clasificacion' => 'No_Perecibles', 'dia_entrega' => 'Viernes', 'fecha' => self::VIERNES, 'hora_llegada' => '15:50']), // pasa el cierre
            $this->turno(403, $codigo, ['duracion_min' => 30]),                                         // duración
            $this->turno(404, 'NO-EXISTE-'.Str::random(5)),                                             // proveedor
            $this->turno(405, $codigo, ['fecha' => self::VIERNES]),                                     // fecha no es el jueves
            $this->turno(406, $codigo, ['clasificacion' => 'Fruver', 'dia_entrega' => 'Lunes', 'fecha' => self::LUNES, 'hora_llegada' => '14:00']), // ya pasó
            $this->turno(407, $codigo, ['clasificacion' => 'Carnes']),                                  // clasificación
            $this->turno(401, $codigo),                                                                 // repetido
            $this->turno(408, $codigo, ['hora_llegada' => '3 pm']),                                     // hora
        ]);

        $respuesta->assertOk()
            ->assertJsonPath('resumen.crear', 1)
            ->assertJsonPath('resumen.error', 8);

        $mensajes = collect($respuesta->json('turnos'))->pluck('mensaje', 'id_bc');
        $this->assertStringContainsString('después del cierre de las 16:00', $mensajes[402]);
        $this->assertStringContainsString('20, 40, 60', $mensajes[403]);
        $this->assertStringContainsString('no está en los proveedores', $mensajes[404]);
        $this->assertStringContainsString('no es el jueves de esta semana', $mensajes[405]);
        $this->assertStringContainsString('ya pasó', $mensajes[406]);
        $this->assertStringContainsString('Clasificación inválida', $mensajes[407]);
        $this->assertStringContainsString('Hora de llegada inválida', $mensajes[408]);
        $this->assertSame('El turno viene repetido en el envío.', $respuesta->json('turnos.7.mensaje'));

        $this->assertSame(1, HorarioEntregaProveedor::where('Id_Empresa', $this->empresa->Id_Empresa)->count());
    }

    public function test_un_turno_de_la_semana_anterior_no_se_crea(): void
    {
        [, , $codigo] = $this->proveedor();

        // BC no lo borró el domingo: viene con la fecha de la semana pasada.
        $this->enviar([$this->turno(501, $codigo, ['fecha' => '2099-02-26'])])
            ->assertOk()
            ->assertJsonPath('resumen.otra_semana', 1)
            ->assertJsonPath('resumen.crear', 0);

        $this->assertNull($this->delPortal(501));
    }

    public function test_lo_que_bc_ya_no_manda_se_da_de_baja_solo_si_no_paso_y_no_llego(): void
    {
        [, , $codigo] = $this->proveedor();

        // Lunes (ya pasó), martes 14:00 (hoy, con arribo), jueves (viene).
        Carbon::setTestNow(self::LUNES.' 08:00');
        $this->enviar([
            $this->turno(601, $codigo, ['clasificacion' => 'Fruver', 'dia_entrega' => 'Lunes', 'fecha' => self::LUNES, 'hora_llegada' => '14:00']),
            $this->turno(602, $codigo, ['clasificacion' => 'Fruver', 'dia_entrega' => 'Martes', 'fecha' => self::MARTES, 'hora_llegada' => '14:00']),
            $this->turno(603, $codigo),
        ])->assertOk()->assertJsonPath('resumen.crear', 3);

        Carbon::setTestNow(self::MARTES.' 14:05');
        HorarioEntregaEstadoDiario::create([
            'Id_Horario_Entrega_Proveedor' => $this->delPortal(602)->Id_Horario_Entrega_Proveedor,
            'Fecha' => self::MARTES,
            'Hora_Arribo_Real' => self::MARTES.'T14:03:00',
            'Fecha_Creacion' => now(),
        ]);

        // BC ya no manda ninguno (p. ej. los borró).
        $this->enviar([])->assertOk()->assertJsonPath('resumen.dar_de_baja', 1)->assertJsonPath('bajas.0.id_bc', 603);

        $this->assertTrue($this->delPortal(601)->Activo, 'El del lunes es historia: no se toca.');
        $this->assertTrue($this->delPortal(602)->Activo, 'Tiene arribo: no se toca.');
        $this->assertFalse($this->delPortal(603)->Activo, 'El del jueves sí se da de baja.');

        // Si vuelve, se reactiva.
        $this->enviar([$this->turno(603, $codigo)])->assertOk()->assertJsonPath('resumen.actualizar', 1);
        $this->assertTrue($this->delPortal(603)->Activo);
    }

    public function test_no_se_cambia_un_turno_al_que_el_proveedor_ya_llego(): void
    {
        [, , $codigo] = $this->proveedor();

        Carbon::setTestNow(self::LUNES.' 08:00');
        $this->enviar([$this->turno(701, $codigo, ['clasificacion' => 'Fruver', 'dia_entrega' => 'Martes', 'fecha' => self::MARTES, 'hora_llegada' => '14:00'])])->assertOk();

        Carbon::setTestNow(self::MARTES.' 13:00');
        HorarioEntregaEstadoDiario::create([
            'Id_Horario_Entrega_Proveedor' => $this->delPortal(701)->Id_Horario_Entrega_Proveedor,
            'Fecha' => self::MARTES,
            'Hora_Arribo_Real' => self::MARTES.'T12:58:00',
            'Fecha_Creacion' => now(),
        ]);

        $this->enviar([$this->turno(701, $codigo, ['clasificacion' => 'Fruver', 'dia_entrega' => 'Martes', 'fecha' => self::MARTES, 'hora_llegada' => '14:20'])])
            ->assertOk()
            ->assertJsonPath('turnos.0.resultado', 'error');
        $this->assertSame('14:00', $this->delPortal(701)->Hora_Llegada);
    }

    public function test_solo_sistemas_o_admin_y_con_token(): void
    {
        [, , $codigo] = $this->proveedor();

        $this->postJson(self::URL, ['empresa_bc' => 'x', 'turnos' => []], [TokenIntegracionBc::CABECERA => 'otro'])->assertStatus(401);

        $compras = $this->crearUsuarioInterno($this->empresa, 'Compras');
        config(['integraciones.bc.usuario' => $compras->Email]);

        $this->enviar([$this->turno(801, $codigo)])->assertStatus(403);
        $this->assertNull($this->delPortal(801));
    }

    public function test_la_sincronizacion_del_calendario_semanal_no_da_de_baja_los_turnos(): void
    {
        [, , $codigo] = $this->proveedor();
        $this->enviar([$this->turno(901, $codigo)])->assertOk();

        // BC sincroniza el calendario semanal de Perecibles (que no trae el turno).
        $this->postJson('/api/integraciones/bc/horarios-entrega/carga-masiva', [
            'empresa_bc' => trim($this->empresa->Empresa_BC),
            'identificador' => 'codigo_bc',
            'confirmar' => true,
            'filas' => [[
                'clasificacion' => 'Perecibles', 'dia_entrega' => 'Lunes', 'identificador' => $codigo,
                'anden_puerta' => 'A', 'hora_llegada' => '07:00', 'tiempo_permanencia' => '30',
            ]],
        ], [TokenIntegracionBc::CABECERA => self::TOKEN])
            ->assertOk()
            ->assertJsonPath('resumen.dar_de_baja', 0);

        $this->assertTrue($this->delPortal(901)->Activo);

        // Y el calendario (pantalla y GET de BC) no lo muestra como horario semanal.
        $listado = $this->getJson('/api/integraciones/bc/horarios-entrega?empresa_bc='.trim($this->empresa->Empresa_BC), [TokenIntegracionBc::CABECERA => self::TOKEN])
            ->assertOk()->json('horarios');
        $this->assertCount(1, $listado);
        $this->assertSame('Lunes', $listado[0]['dia_entrega']);
    }

    // ------------------------------------------------------------------
    // Seguimiento de hoy, Guardia y tolerancia
    // ------------------------------------------------------------------

    private function turnoDeHoy(string $hora = '14:00'): HorarioEntregaProveedor
    {
        [, , $codigo] = $this->proveedor();
        Carbon::setTestNow(self::MARTES.' 08:00');
        $this->enviar([$this->turno(1001, $codigo, ['clasificacion' => 'Fruver', 'dia_entrega' => 'Martes', 'fecha' => self::MARTES, 'hora_llegada' => $hora])])
            ->assertOk()->assertJsonPath('resumen.crear', 1);

        return $this->delPortal(1001);
    }

    public function test_sale_en_el_seguimiento_solo_el_dia_de_su_fecha(): void
    {
        $turno = $this->turnoDeHoy();
        $guardia = $this->crearUsuarioInterno($this->empresa, 'Guardia');

        // Un horario semanal del martes también sale, como siempre.
        HorarioEntregaProveedor::create([
            'Id_Empresa' => $this->empresa->Id_Empresa, 'Id_Proveedor' => $turno->Id_Proveedor,
            'Clasificacion' => 'Fruver', 'Dia_Entrega' => 'Martes', 'Hora_Llegada' => '07:00', 'Activo' => 1,
        ]);

        Carbon::setTestNow(self::MARTES.' 13:00');
        $hoy = collect($this->getJson('/api/horarios-entrega/hoy', $this->cabecerasComo($guardia, $this->empresa))->assertOk()->json());
        $this->assertCount(2, $hoy);
        $fila = $hoy->firstWhere('id_horario_entrega_proveedor', $turno->Id_Horario_Entrega_Proveedor);
        $this->assertTrue($fila['fuera_planificacion']);
        $this->assertSame(self::MARTES, $fila['fecha_fuera_planificacion']);
        $this->assertSame('Programado', $fila['estado']);
        $this->assertFalse($hoy->firstWhere('hora_llegada', '07:00')['fuera_planificacion']);

        // El martes siguiente NO vuelve a salir (no se repite como la plantilla).
        Carbon::setTestNow('2099-03-10 13:00');
        $siguiente = collect($this->getJson('/api/horarios-entrega/hoy', $this->cabecerasComo($guardia, $this->empresa))->assertOk()->json());
        $this->assertCount(1, $siguiente);
        $this->assertSame('07:00', $siguiente->first()['hora_llegada']);
    }

    public function test_el_guardia_marca_hasta_con_diez_minutos_de_atraso(): void
    {
        $turno = $this->turnoDeHoy('14:00');
        $guardia = $this->crearUsuarioInterno($this->empresa, 'Guardia');
        $url = "/api/horarios-entrega/{$turno->Id_Horario_Entrega_Proveedor}/marcar-arribo";

        // 10 minutos justos: todavía se puede.
        Carbon::setTestNow(self::MARTES.' 14:10:00');
        $this->postJson($url, [], $this->cabecerasComo($guardia, $this->empresa))->assertOk()->assertJsonPath('estado', 'Arribo');
    }

    public function test_pasados_diez_minutos_no_se_marca_ni_se_pide_aprobacion(): void
    {
        $turno = $this->turnoDeHoy('14:00');
        $guardia = $this->crearUsuarioInterno($this->empresa, 'Guardia');
        $cabeceras = $this->cabecerasComo($guardia, $this->empresa);

        Carbon::setTestNow(self::MARTES.' 14:10:01');

        $estado = collect($this->getJson('/api/horarios-entrega/hoy', $cabeceras)->json())
            ->firstWhere('id_horario_entrega_proveedor', $turno->Id_Horario_Entrega_Proveedor)['estado'];
        $this->assertSame('Rechazado', $estado);

        $this->postJson("/api/horarios-entrega/{$turno->Id_Horario_Entrega_Proveedor}/marcar-arribo", [], $this->cabecerasComo($guardia, $this->empresa))
            ->assertStatus(422)
            ->assertJsonFragment(['Este pedido fuera de planificación ya pasó los 10 min de tolerancia: no se puede registrar el arribo.']);

        $this->postJson("/api/horarios-entrega/{$turno->Id_Horario_Entrega_Proveedor}/solicitar-aprobacion", [], $this->cabecerasComo($guardia, $this->empresa))
            ->assertStatus(422);

        $this->assertFalse(HorarioEntregaEstadoDiario::where('Id_Horario_Entrega_Proveedor', $turno->Id_Horario_Entrega_Proveedor)->whereNotNull('Hora_Arribo_Real')->exists());
    }

    public function test_el_calendario_semanal_sigue_con_sus_30_minutos(): void
    {
        [, $proveedor] = $this->proveedor();
        $semanal = HorarioEntregaProveedor::create([
            'Id_Empresa' => $this->empresa->Id_Empresa, 'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => 'Perecibles', 'Dia_Entrega' => 'Martes', 'Hora_Llegada' => '09:00', 'Activo' => 1,
        ]);

        $this->assertFalse($semanal->excedeTolerancia(29 * 60 + 59));
        $this->assertTrue($semanal->excedeTolerancia(30 * 60));

        $guardia = $this->crearUsuarioInterno($this->empresa, 'Guardia');
        Carbon::setTestNow(self::MARTES.' 09:25');
        $this->postJson("/api/horarios-entrega/{$semanal->Id_Horario_Entrega_Proveedor}/marcar-arribo", [], $this->cabecerasComo($guardia, $this->empresa))
            ->assertOk();
    }

    public function test_el_modo_tv_lo_muestra_aunque_no_tenga_pedidos_recientes(): void
    {
        $turno = $this->turnoDeHoy();
        Carbon::setTestNow(self::MARTES.' 13:00');

        $filas = app(HorarioEntregaService::class)->listarDeHoy($this->sistemas, $this->empresa->Id_Empresa, null, true, true);

        $this->assertTrue($filas->contains('id_horario_entrega_proveedor', $turno->Id_Horario_Entrega_Proveedor));
    }

    // ------------------------------------------------------------------
    // Sección del portal, proveedor e historial
    // ------------------------------------------------------------------

    public function test_seccion_de_la_semana_para_compras_y_no_para_el_guardia(): void
    {
        [, , $codigo] = $this->proveedor();
        $this->enviar([
            $this->turno(1101, $codigo),
            $this->turno(1102, $codigo, ['clasificacion' => 'Fruver', 'dia_entrega' => 'Sabado', 'fecha' => self::SABADO, 'hora_llegada' => '15:00']),
        ])->assertOk()->assertJsonPath('resumen.crear', 2);

        $compras = $this->crearUsuarioInterno($this->empresa, 'Compras');
        $respuesta = $this->getJson('/api/horarios-entrega/fuera-planificacion', $this->cabecerasComo($compras, $this->empresa))->assertOk();

        $this->assertSame('2099-03-01 01:00', $respuesta->json('inicio_ciclo'));
        $this->assertSame(10, $respuesta->json('tolerancia_minutos'));
        $this->assertCount(2, $respuesta->json('turnos'));
        $this->assertSame(self::JUEVES, $respuesta->json('turnos.0.fecha_fuera_planificacion'));
        $this->assertSame('Programado', $respuesta->json('turnos.0.estado'));
        $this->assertSame(['15:00', '17:00'], [$respuesta->json('ventanas.Perecibles.0.apertura'), $respuesta->json('ventanas.Perecibles.0.cierre')]);

        $soloFruver = $this->getJson('/api/horarios-entrega/fuera-planificacion?clasificacion=Fruver', $this->cabecerasComo($compras, $this->empresa))->assertOk();
        $this->assertCount(1, $soloFruver->json('turnos'));

        // El calendario semanal de la pantalla no los trae.
        $this->assertCount(0, $this->getJson('/api/horarios-entrega', $this->cabecerasComo($compras, $this->empresa))->assertOk()->json());

        $guardia = $this->crearUsuarioInterno($this->empresa, 'Guardia');
        $this->getJson('/api/horarios-entrega/fuera-planificacion', $this->cabecerasComo($guardia, $this->empresa))->assertStatus(403);

        // Desde el domingo 01:00 la sección empieza vacía (semana nueva).
        Carbon::setTestNow('2099-03-08 01:00');
        $this->assertCount(0, $this->getJson('/api/horarios-entrega/fuera-planificacion', $this->cabecerasComo($compras, $this->empresa))->json('turnos'));
        $this->assertNotNull($this->delPortal(1101), 'En la base queda: es historia.');
    }

    public function test_el_proveedor_ve_su_turno_con_la_fecha(): void
    {
        [$usuario, , $codigo] = $this->proveedor();
        $this->enviar([$this->turno(1201, $codigo)])->assertOk();

        $mios = collect($this->getJson('/api/horarios-entrega/mios', $this->cabecerasComo($usuario, $this->empresa))->assertOk()->json());

        $this->assertCount(1, $mios);
        $this->assertTrue($mios->first()['fuera_planificacion']);
        $this->assertSame(self::JUEVES, $mios->first()['proxima_fecha']);

        // Pasado su día ya no le sale.
        Carbon::setTestNow('2099-03-06 08:00');
        $this->assertCount(0, $this->getJson('/api/horarios-entrega/mios', $this->cabecerasComo($usuario, $this->empresa))->json());
    }

    public function test_el_historial_lo_marca_y_usa_los_diez_minutos(): void
    {
        $turno = $this->turnoDeHoy('14:00');
        $guardia = $this->crearUsuarioInterno($this->empresa, 'Guardia');

        // Llegó 8 minutos tarde: Atrasado (con 30 de tolerancia también lo sería,
        // así que se prueba además el corte con el cálculo del estado).
        Carbon::setTestNow(self::MARTES.' 14:08');
        $this->postJson("/api/horarios-entrega/{$turno->Id_Horario_Entrega_Proveedor}/marcar-arribo", [], $this->cabecerasComo($guardia, $this->empresa))->assertOk();

        Carbon::setTestNow(self::MARTES.' 18:00');
        app(Historial::class)->procesarDia(Carbon::parse(self::MARTES), Historial::MODO_HOY);

        $fila = HistorialHorarioProveedor::where('Id_Horario', $turno->Id_Horario_Entrega_Proveedor)->where('Fecha', self::MARTES)->first();
        $this->assertNotNull($fila);
        $this->assertSame('1', (string) (int) $fila->Fuera_Planificacion);
        $this->assertSame('Atrasado', $fila->Puntualidad);
        $this->assertSame(8, (int) $fila->Min_Atraso);

        // Con los 10 min de tolerancia, 12 min tarde es fuera de plazo.
        $this->assertTrue($turno->excedeTolerancia(12 * 60));
        $this->assertFalse($turno->excedeTolerancia(10 * 60));
    }
}
