<?php

namespace Tests\Feature\HorariosEntrega;

use App\Models\Empresa;
use App\Modules\Horarios_Entrega\Models\HistorialHorarioProveedor;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Models\SolicitudAprobacionArribo;
use App\Modules\Horarios_Entrega\Services\HistorialHorarioProveedorService as Historial;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Historial_Horario_Proveedor (16-nov-2026): una fila por cita, con los
 * tiempos del portal (0 min de gracia, 30 min = fuera de plazo), sin las
 * citas sin pedido y sin llegada.
 *
 * Todo pasa en un día de 2099 y con una Empresa_BC propia: no se cruza con
 * datos reales (los horarios reales de ese día de la semana no tienen
 * pedidos en 2099, así que no generan filas). DatabaseTransactions deshace
 * todo al terminar.
 */
class HistorialHorarioProveedorTest extends TestCase
{
    private const DIA = '2099-03-02';

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.minutos_atrasado_a_rechazado' => 30]);
        $this->empresa = $this->crearEmpresa(['Empresa_BC' => 'hht' . Str::lower(Str::random(8))]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function historial(): Historial
    {
        return app(Historial::class);
    }

    private function nombreDia(string $fecha): string
    {
        return [0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado'][Carbon::parse($fecha)->dayOfWeek];
    }

    private function proveedor(): Proveedor
    {
        [, $proveedor] = $this->crearProveedorConUsuario($this->empresa);

        return $proveedor;
    }

    private function cita(Proveedor $proveedor, string $hora, array $extra = []): HorarioEntregaProveedor
    {
        return HorarioEntregaProveedor::create([
            'Id_Empresa' => $this->empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => 'Fruver',
            'Dia_Entrega' => $this->nombreDia(self::DIA),
            'Anden_Puerta' => 'A1',
            'Hora_Llegada' => $hora,
            'Hora_Salida' => '12:00',
            'Activo' => 1,
            'Fecha_Creacion' => '2099-01-01T00:00:00',
            ...$extra,
        ]);
    }

    /** Ficha del proveedor en BC (enganche por RUC) y, si $conPedido, un pedido con entrega ese día. */
    private function enBc(Proveedor $proveedor, string $codigo, bool $conPedido = true, array $ficha = []): void
    {
        $empresaBc = trim($this->empresa->Empresa_BC);

        DB::table('BC_Ficha_Proveedor')->insert([
            'Empresa' => $empresaBc,
            'Fecha_Registro' => now()->format('Y-m-d\TH:i:s'),
            'Nro_Proveedor' => $codigo,
            'Nombre' => $proveedor->Razon_Social,
            'Nro_Identificacion' => $proveedor->Ruc,
            'Bloqueado' => ' ',
            ...$ficha,
        ]);

        if ($conPedido) {
            DB::table('BC_Cab_Pedido_Compra')->insert([
                'Empresa' => $empresaBc,
                'Fecha_Registro' => now()->format('Y-m-d\TH:i:s'),
                'Tipo_Documento' => 'Order',
                'Nro_Pedido' => 'PED-' . Str::upper(Str::random(8)),
                'Nro_Proveedor' => $codigo,
                'Fecha_Recepcion_Esperada' => self::DIA . 'T00:00:00',
                'Estado_Pedido' => 'Released',
            ]);
        }
    }

    private function marcas(HorarioEntregaProveedor $cita, ?string $arribo, ?string $recepcion = null, ?string $recibido = null): void
    {
        HorarioEntregaEstadoDiario::create([
            'Id_Horario_Entrega_Proveedor' => $cita->Id_Horario_Entrega_Proveedor,
            'Fecha' => self::DIA,
            'Hora_Arribo_Real' => $arribo ? self::DIA . "T{$arribo}" : null,
            'Hora_Recepcion_Real' => $recepcion ? self::DIA . "T{$recepcion}" : null,
            'Hora_Entregado_Real' => $recibido ? self::DIA . "T{$recibido}" : null,
            'Fecha_Creacion' => now(),
        ]);
    }

    private function solicitud(HorarioEntregaProveedor $cita, string $hora, string $estado): SolicitudAprobacionArribo
    {
        return SolicitudAprobacionArribo::create([
            'Id_Horario_Entrega_Proveedor' => $cita->Id_Horario_Entrega_Proveedor,
            'Fecha' => self::DIA,
            'Solicitado_Por' => $this->crearUsuarioInterno($this->empresa, 'Guardia')->Id_Usuario,
            'Fecha_Solicitud' => self::DIA . "T{$hora}",
            'Estado' => $estado,
        ]);
    }

    private function fila(HorarioEntregaProveedor $cita): ?HistorialHorarioProveedor
    {
        return HistorialHorarioProveedor::where('Id_Horario', $cita->Id_Horario_Entrega_Proveedor)->where('Fecha', self::DIA)->first();
    }

    private function correrHoy(string $hora): array
    {
        Carbon::setTestNow(self::DIA . " {$hora}");

        return $this->historial()->procesarDia(Carbon::parse(self::DIA), Historial::MODO_HOY);
    }

    private function correrDiaSiguiente(string $modo = Historial::MODO_REVISAR): array
    {
        Carbon::setTestNow(Carbon::parse(self::DIA)->addDay()->setTime(8, 0));

        return $this->historial()->procesarDia(Carbon::parse(self::DIA), $modo);
    }

    public function test_a_tiempo_y_recibido_con_espera_y_descarga(): void
    {
        $proveedor = $this->proveedor();
        $cita = $this->cita($proveedor, '05:00');
        $this->enBc($proveedor, 'PROV-T001');
        $this->marcas($cita, '04:52:10', '05:10:30', '05:48:59');

        $this->correrHoy('09:00');
        $fila = $this->fila($cita);

        $this->assertNotNull($fila);
        $this->assertSame('PROV-T001', $fila->Cod_Proveedor);
        $this->assertSame($proveedor->Razon_Social, $fila->Proveedor);
        $this->assertSame('05:00:00', substr($fila->Hora_Programada, 0, 8));
        $this->assertSame('04:52:10', substr($fila->Hora_Arribo, 0, 8));
        $this->assertSame(-7, (int) $fila->Min_Atraso); // -7 min 50 s: minutos completos
        $this->assertSame(1, (int) $fila->Llego);
        $this->assertSame('A tiempo', $fila->Puntualidad);
        $this->assertSame('Recibido', $fila->Estado);
        $this->assertSame(18, (int) $fila->Min_Espera);   // 04:52:10 -> 05:10:30
        $this->assertSame(38, (int) $fila->Min_Descarga); // 05:10:30 -> 05:48:59
        $this->assertSame('Diario', $fila->Origen);
    }

    /** Los cortes del portal: 0 min de gracia, menos de 30 = Atrasado, 30 o más = Fuera de plazo. */
    public function test_los_cortes_son_los_del_portal(): void
    {
        $casos = [
            '07:00:00' => ['A tiempo', 0],
            '07:00:20' => ['Atrasado', 0],  // un segundo tarde ya es Atrasado
            '07:29:59' => ['Atrasado', 29],
            '07:30:00' => ['Fuera de plazo', 30],
        ];

        $citas = [];
        foreach ($casos as $arribo => $esperado) {
            $proveedor = $this->proveedor();
            $cita = $this->cita($proveedor, '07:00');
            $this->enBc($proveedor, 'PROV-' . Str::upper(Str::random(6)));
            $this->marcas($cita, $arribo);
            $citas[$arribo] = $cita;
        }

        $this->correrHoy('09:00');

        foreach ($casos as $arribo => [$puntualidad, $minutos]) {
            $fila = $this->fila($citas[$arribo]);
            $this->assertSame($puntualidad, $fila->Puntualidad, "Arribo {$arribo}");
            $this->assertSame($minutos, (int) $fila->Min_Atraso, "Arribo {$arribo}");
        }
    }

    public function test_el_limite_de_30_sale_del_config_del_portal(): void
    {
        config(['portal.minutos_atrasado_a_rechazado' => 20]);
        $proveedor = $this->proveedor();
        $cita = $this->cita($proveedor, '07:00');
        $this->enBc($proveedor, 'PROV-T002');
        $this->marcas($cita, '07:25:00');

        $this->correrHoy('09:00');

        $this->assertSame('Fuera de plazo', $this->fila($cita)->Puntualidad);
    }

    /** Llegó 30 min tarde o más: el Guardia solo pudo pedir aprobación, no hay marca de arribo. */
    public function test_fuera_de_plazo_por_aprobar_y_rechazado_si_aparecen(): void
    {
        $p1 = $this->proveedor();
        $pendiente = $this->cita($p1, '06:00');
        $this->enBc($p1, 'PROV-T003');
        $this->solicitud($pendiente, '06:45:00', 'Pendiente');

        $p2 = $this->proveedor();
        $rechazada = $this->cita($p2, '06:00');
        $this->enBc($p2, 'PROV-T004');
        $this->solicitud($rechazada, '07:10:00', 'Rechazada');

        $this->correrHoy('09:00');

        $fila = $this->fila($pendiente);
        $this->assertSame(1, (int) $fila->Llego);
        $this->assertSame('06:45:00', substr($fila->Hora_Arribo, 0, 8));
        $this->assertSame(45, (int) $fila->Min_Atraso);
        $this->assertSame('Fuera de plazo', $fila->Puntualidad);
        $this->assertSame('Por aprobar', $fila->Estado);
        $this->assertSame('Pendiente', $fila->Aprobacion);

        $fila = $this->fila($rechazada);
        $this->assertSame('Fuera de plazo', $fila->Puntualidad);
        $this->assertSame('Rechazado', $fila->Estado);
        $this->assertSame('Rechazada', $fila->Aprobacion);
    }

    /** "Los que digan sin pedido no deben salir" (decisión del usuario). */
    public function test_sin_pedido_y_sin_llegada_no_aparece(): void
    {
        $proveedor = $this->proveedor();
        $cita = $this->cita($proveedor, '08:00');
        $this->enBc($proveedor, 'PROV-T005', conPedido: false);

        $this->correrHoy('10:00');
        $this->assertNull($this->fila($cita));

        $this->correrDiaSiguiente();
        $this->assertNull($this->fila($cita));
    }

    public function test_sin_pedido_pero_llego_si_aparece(): void
    {
        $proveedor = $this->proveedor();
        $cita = $this->cita($proveedor, '08:00');
        $this->enBc($proveedor, 'PROV-T006', conPedido: false);
        $this->marcas($cita, '08:05:00');

        $this->correrHoy('10:00');

        $this->assertSame('Atrasado', $this->fila($cita)->Puntualidad);
    }

    /** Durante el día va por los estados del portal; al día siguiente queda "No llegó". */
    public function test_sin_llegada_pasa_de_programado_a_no_llego(): void
    {
        $proveedor = $this->proveedor();
        $cita = $this->cita($proveedor, '08:00');
        $this->enBc($proveedor, 'PROV-T007');

        $this->correrHoy('07:30');
        $fila = $this->fila($cita);
        $this->assertSame('Programado', $fila->Estado);
        $this->assertSame('Pendiente', $fila->Puntualidad);
        $this->assertSame(0, (int) $fila->Llego);
        $this->assertNull($fila->Min_Atraso);

        $this->correrHoy('08:20');
        $fila = $this->fila($cita);
        $this->assertSame('Atrasado', $fila->Estado);
        $this->assertSame(20, (int) $fila->Min_Atraso);

        $this->correrHoy('08:45');
        $this->assertSame('Rechazado', $this->fila($cita)->Estado);

        $this->correrDiaSiguiente();
        $fila = $this->fila($cita);
        $this->assertSame('No llegó', $fila->Estado);
        $this->assertSame('No llegó', $fila->Puntualidad);
        $this->assertNull($fila->Min_Atraso);
    }

    /** Las aprobaciones se resuelven días después: la revisión lo refleja sin tocar la foto. */
    public function test_la_revision_actualiza_la_aprobacion_y_no_toca_la_foto(): void
    {
        $proveedor = $this->proveedor();
        $cita = $this->cita($proveedor, '06:00');
        $this->enBc($proveedor, 'PROV-T008');
        $solicitud = $this->solicitud($cita, '06:50:00', 'Pendiente');

        $this->correrHoy('09:00');
        $this->assertSame('Por aprobar', $this->fila($cita)->Estado);

        // Días después: Calidad aprueba (crea la marca de arribo con la hora
        // de la solicitud) y BC le cambia el andén al horario.
        $solicitud->update(['Estado' => 'Aprobada']);
        $this->marcas($cita, '06:50:00');
        $cita->update(['Anden_Puerta' => 'Z9']);

        $resultado = $this->correrDiaSiguiente();
        $fila = $this->fila($cita);

        $this->assertSame(1, $resultado['actualizadas']);
        $this->assertSame('Arribo', $fila->Estado);
        $this->assertSame('Aprobada', $fila->Aprobacion);
        $this->assertSame('Fuera de plazo', $fila->Puntualidad);
        $this->assertSame('A1', $fila->Anden, 'La foto del día no cambia.');
    }

    public function test_hoy_quita_la_cita_si_el_pedido_desaparece_y_no_llego(): void
    {
        $proveedor = $this->proveedor();
        $cita = $this->cita($proveedor, '08:00');
        $this->enBc($proveedor, 'PROV-T009');

        $this->correrHoy('07:00');
        $this->assertNotNull($this->fila($cita));

        DB::table('BC_Cab_Pedido_Compra')->where('Empresa', trim($this->empresa->Empresa_BC))->delete();

        $resultado = $this->correrHoy('07:05');
        $this->assertSame(1, $resultado['quitadas']);
        $this->assertNull($this->fila($cita));
    }

    public function test_reconstruir_usa_el_calendario_de_ese_dia(): void
    {
        $antes = $this->proveedor();
        $citaVieja = $this->cita($antes, '08:00');
        $this->enBc($antes, 'PROV-T010');

        // Creado después de ese día: no estaba en el calendario.
        $despues = $this->proveedor();
        $citaNueva = $this->cita($despues, '08:00', ['Fecha_Creacion' => '2099-04-01T00:00:00']);
        $this->enBc($despues, 'PROV-T011');

        // Dado de baja después de ese día: sí estaba.
        $baja = $this->proveedor();
        $citaBaja = $this->cita($baja, '09:00', ['Activo' => 0, 'Fecha_Modificacion' => '2099-03-10T09:00:00']);
        $this->enBc($baja, 'PROV-T012');

        $this->correrDiaSiguiente(Historial::MODO_RECONSTRUIR);

        $this->assertSame('Reconstruido', $this->fila($citaVieja)->Origen);
        $this->assertSame('No llegó', $this->fila($citaVieja)->Estado);
        $this->assertNull($this->fila($citaNueva));
        $this->assertNotNull($this->fila($citaBaja));
    }

    /** RUC con dos fichas en BC (PRINTOPAC): se elige la vigente, no la "INACTIVO". */
    public function test_codigo_bc_con_dos_fichas_elige_la_vigente(): void
    {
        $proveedor = $this->proveedor();
        $cita = $this->cita($proveedor, '08:00');
        $this->enBc($proveedor, 'PROV-VIEJO', conPedido: false, ficha: ['Nombre' => 'INACTIVO ' . $proveedor->Razon_Social]);
        $this->enBc($proveedor, 'PROV-NUEVO', conPedido: false);
        $this->marcas($cita, '07:55:00');

        $this->correrHoy('10:00');

        $this->assertSame('PROV-NUEVO', $this->fila($cita)->Cod_Proveedor);
    }

    public function test_simular_no_escribe(): void
    {
        $proveedor = $this->proveedor();
        $cita = $this->cita($proveedor, '05:00');
        $this->enBc($proveedor, 'PROV-T013');

        Carbon::setTestNow(self::DIA . ' 09:00');
        $resultado = $this->historial()->procesarDia(Carbon::parse(self::DIA), Historial::MODO_HOY, simular: true);

        $this->assertGreaterThanOrEqual(1, $resultado['agregadas']);
        $this->assertNull($this->fila($cita));
    }

    public function test_antes_del_primer_dia_no_procesa_nada(): void
    {
        Carbon::setTestNow('2026-11-20 08:00');
        $resultado = $this->historial()->procesarDia(Carbon::parse('2026-11-10'), Historial::MODO_RECONSTRUIR, simular: true);

        $this->assertSame([], $resultado['filas']);
    }

    public function test_correr_dos_veces_no_duplica_ni_reescribe(): void
    {
        $proveedor = $this->proveedor();
        $cita = $this->cita($proveedor, '05:00');
        $this->enBc($proveedor, 'PROV-T014');
        $this->marcas($cita, '05:02:00', '05:20:00', '06:00:00');

        $this->correrHoy('09:00');
        $segunda = $this->correrHoy('09:00');

        $this->assertSame(1, HistorialHorarioProveedor::where('Id_Horario', $cita->Id_Horario_Entrega_Proveedor)->count());
        $this->assertSame(0, $segunda['actualizadas']);
    }
}
