<?php

namespace Tests\Feature\HorariosEntrega;

use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Services\SincronizacionSighService;
use Tests\TestCase;

/**
 * Horas reales de recepción y entrega tomadas de SIGH (12-nov-2026).
 *
 * Hasta ese día el sincronizador guardaba la hora en que corría (now()):
 * con SIGH caído hasta las 11:37, todas las entregas del 12-nov quedaron a
 * las 11:38. Y aceptaba como ingreso movimientos viejos de otros pedidos
 * con el mismo número de ítem.
 *
 * En las pruebas no hay conexión a SIGH: se reemplaza el service por uno
 * falso que devuelve lo que cada prueba necesita.
 */
class HorasRealesSighTest extends TestCase
{
    /**
     * SIGH falso: cada prueba le dice qué responder.
     *
     * $documentosDelProveedor es [Id_Proveedor => [docs]]: SOLO el
     * proveedor de la prueba tiene pedidos. Las pruebas corren contra la
     * base del .env con rollback, y el job recorre todas las entregas del
     * día, también las reales: si el falso les diera documentos, el job las
     * actualizaría (y las bloquearía) mientras dura la prueba, chocando con
     * el job de verdad que las toca cada minuto.
     */
    private function sighFalso(array $documentosDelProveedor, array $ingresos, array $recibidos = [], bool $falla = false): void
    {
        $falso = new class($documentosDelProveedor, $ingresos, $recibidos, $falla) extends SincronizacionSighService
        {
            public function __construct(
                private array $docs,
                private array $ingresos,
                private array $recibidos,
                private bool $falla,
            ) {
            }

            public function documentosBcDelProveedor(int $idEmpresa, int $idProveedor): array
            {
                return $this->docs[$idProveedor] ?? [];
            }

            public function documentosConIngresoEnDocumentoInv(array $nrosDocumentoBc, bool $lanzarSiFalla = false): array
            {
                if ($this->falla && $lanzarSiFalla) {
                    throw new \RuntimeException('SIGH no responde');
                }

                return $this->falla ? [] : array_intersect_key($this->ingresos, array_flip($nrosDocumentoBc));
            }

            public function documentosRecibidos(array $nrosDocumentoBc, bool $lanzarSiFalla = false): array
            {
                if ($this->falla && $lanzarSiFalla) {
                    throw new \RuntimeException('SIGH no responde');
                }

                return $this->falla ? [] : array_intersect_key($this->recibidos, array_flip($nrosDocumentoBc));
            }
        };

        $this->app->instance(SincronizacionSighService::class, $falso);
    }

    /**
     * Entrega con el arribo ya marcado. Por defecto hoy (lo que mira el
     * job). Las pruebas de la corrección usan un día de 2099, que no existe
     * en la base: así el comando, que recorre todas las filas desde
     * --desde, solo encuentra las de la prueba.
     */
    private function entregaConArribo(string $horaArribo = '05:04', array $extra = [], ?string $fecha = null): HorarioEntregaEstadoDiario
    {
        $fecha ??= now()->toDateString();
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $horario = HorarioEntregaProveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => 'Fruver',
            'Dia_Entrega' => 'Lunes',
            'Hora_Llegada' => '05:00',
            'Activo' => 1,
            'Fecha_Creacion' => now(),
        ]);

        return HorarioEntregaEstadoDiario::create([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => $fecha,
            'Hora_Arribo_Real' => "{$fecha} {$horaArribo}:00",
            'Fecha_Creacion' => now(),
            ...$extra,
        ]);
    }

    // ------------------------------------------------------------------
    // El job de cada minuto
    // ------------------------------------------------------------------

    public function test_guarda_la_hora_real_del_ingreso_y_no_la_hora_del_job(): void
    {
        $estado = $this->entregaConArribo();
        $hoy = now()->toDateString();
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-1']], ['CP-PD-1' => "{$hoy} 05:45:22"], ['CP-PD-1' => true]);

        $this->artisan('horarios-entrega:sincronizar-sigh')->assertSuccessful();

        $estado->refresh();
        $this->assertSame('05:45', $estado->Hora_Recepcion_Real->format('H:i'));
        // En SIGH el pedido se cierra en el mismo acto del ingreso.
        $this->assertSame('05:45', $estado->Hora_Entregado_Real->format('H:i'));
        $this->assertSame('CP-PD-1', $estado->Nro_Documento_Bc);
    }

    public function test_un_ingreso_de_otro_dia_no_marca_la_entrega_de_hoy(): void
    {
        // Caso real del 12-nov: el pedido del proveedor se había recibido
        // el 10-nov y la entrega de hoy quedaba "recibida" al instante.
        $estado = $this->entregaConArribo();
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-VIEJO']], ['CP-PD-VIEJO' => now()->subDays(2)->toDateString() . ' 15:09:50'], ['CP-PD-VIEJO' => true]);

        $this->artisan('horarios-entrega:sincronizar-sigh')->assertSuccessful();

        $estado->refresh();
        $this->assertNull($estado->Hora_Recepcion_Real);
        $this->assertNull($estado->Hora_Entregado_Real);
    }

    public function test_si_hay_dos_ingresos_del_dia_toma_el_primero(): void
    {
        $estado = $this->entregaConArribo();
        $hoy = now()->toDateString();
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-A', 'CP-PD-B']], ['CP-PD-A' => "{$hoy} 09:28:00", 'CP-PD-B' => "{$hoy} 06:10:00"]);

        $this->artisan('horarios-entrega:sincronizar-sigh')->assertSuccessful();

        $estado->refresh();
        $this->assertSame('06:10', $estado->Hora_Recepcion_Real->format('H:i'));
        $this->assertSame('CP-PD-B', $estado->Nro_Documento_Bc);
    }

    // ------------------------------------------------------------------
    // Corrección del histórico (todo en un día de 2099, que no existe en
    // la base: así el comando solo encuentra las filas de la prueba)
    // ------------------------------------------------------------------

    private const DIA = '2099-01-05';

    private function corregir(array $opciones = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('horarios-entrega:corregir-horas-sigh', ['--desde' => self::DIA] + $opciones);
    }

    private function respaldosActuales(): array
    {
        return glob(storage_path('app/respaldos/horas-sigh-*.json')) ?: [];
    }

    private function borrarRespaldosNuevos(array $antes): void
    {
        foreach (array_diff($this->respaldosActuales(), $antes) as $f) {
            @unlink($f);
        }
    }

    public function test_la_correccion_por_defecto_solo_simula(): void
    {
        $estado = $this->entregaConArribo('05:04', [
            'Hora_Recepcion_Real' => self::DIA . ' 11:38:07',
            'Hora_Entregado_Real' => self::DIA . ' 11:38:07',
            'Nro_Documento_Bc' => 'CP-PD-1',
        ], self::DIA);
        $this->sighFalso([], ['CP-PD-1' => self::DIA . ' 05:45:22'], ['CP-PD-1' => true]);

        $this->corregir()->assertSuccessful();

        $this->assertSame('11:38', $estado->fresh()->Hora_Recepcion_Real->format('H:i'));
    }

    public function test_con_aplicar_corrige_la_hora_y_se_puede_restaurar(): void
    {
        $estado = $this->entregaConArribo('05:04', [
            'Hora_Recepcion_Real' => self::DIA . ' 11:38:07',
            'Hora_Entregado_Real' => self::DIA . ' 11:38:07',
            'Nro_Documento_Bc' => 'CP-PD-1',
        ], self::DIA);
        $this->sighFalso([], ['CP-PD-1' => self::DIA . ' 05:45:22'], ['CP-PD-1' => true]);
        $antes = $this->respaldosActuales();

        $this->corregir(['--aplicar' => true])->assertSuccessful();

        $estado->refresh();
        $this->assertSame('05:45', $estado->Hora_Recepcion_Real->format('H:i'));
        $this->assertSame('05:45', $estado->Hora_Entregado_Real->format('H:i'));

        // Dejó un respaldo nuevo, y con él se vuelve a como estaba.
        $nuevos = array_values(array_diff($this->respaldosActuales(), $antes));
        $this->assertCount(1, $nuevos);

        $this->artisan('horarios-entrega:corregir-horas-sigh', ['--restaurar' => $nuevos[0]])->assertSuccessful();
        $this->assertSame('11:38', $estado->fresh()->Hora_Recepcion_Real->format('H:i'));

        $this->borrarRespaldosNuevos($antes);
    }

    public function test_una_marca_falsa_se_quita(): void
    {
        // Caso real del 12-nov: CP-PD-0000035590 seguía sin recibir en SIGH
        // (EstadoPedido 'S'), pero había quedado "en recepción" por un
        // movimiento de marzo con el mismo número de ítem.
        $estado = $this->entregaConArribo('11:50', [
            'Hora_Recepcion_Real' => self::DIA . ' 11:51:00',
            'Nro_Documento_Bc' => 'CP-PD-SIN-RECIBIR',
        ], self::DIA);
        $this->sighFalso([], []);
        $antes = $this->respaldosActuales();

        $this->corregir(['--aplicar' => true])->assertSuccessful();

        $estado->refresh();
        $this->assertNull($estado->Hora_Recepcion_Real);
        $this->assertNull($estado->Nro_Documento_Bc);

        $this->borrarRespaldosNuevos($antes);
    }

    public function test_un_ingreso_de_otro_dia_cuenta_como_marca_falsa(): void
    {
        $estado = $this->entregaConArribo('09:00', [
            'Hora_Recepcion_Real' => self::DIA . ' 11:38:07',
            'Nro_Documento_Bc' => 'CP-PD-VIEJO',
        ], self::DIA);
        $this->sighFalso([], ['CP-PD-VIEJO' => '2099-01-02 15:09:50'], ['CP-PD-VIEJO' => true]);
        $antes = $this->respaldosActuales();

        $this->corregir(['--aplicar' => true])->assertSuccessful();

        $this->assertNull($estado->fresh()->Hora_Recepcion_Real);

        $this->borrarRespaldosNuevos($antes);
    }

    public function test_si_sigh_falla_no_toca_nada(): void
    {
        $estado = $this->entregaConArribo('05:04', [
            'Hora_Recepcion_Real' => self::DIA . ' 11:38:07',
            'Nro_Documento_Bc' => 'CP-PD-1',
        ], self::DIA);
        $this->sighFalso([], [], [], falla: true);

        // Sin el modo estricto, un fallo de SIGH se leería como "no hubo
        // ingreso" y borraría horas buenas.
        $this->corregir(['--aplicar' => true])->assertFailed();

        $this->assertSame('11:38', $estado->fresh()->Hora_Recepcion_Real->format('H:i'));
    }

    public function test_no_toca_las_marcas_manuales(): void
    {
        $usuario = $this->crearUsuarioInterno($this->crearEmpresa(), 'Guardia');
        $estado = $this->entregaConArribo('05:04', [
            'Hora_Recepcion_Real' => self::DIA . ' 07:00:00',
            'Hora_Entregado_Real' => self::DIA . ' 07:30:00',
            'Marcado_Entregado_Por' => $usuario->Id_Usuario,
            'Nro_Documento_Bc' => 'CP-PD-1',
        ], self::DIA);
        $this->sighFalso([], []);

        $this->corregir(['--aplicar' => true])->assertSuccessful();

        $this->assertSame('07:30', $estado->fresh()->Hora_Entregado_Real->format('H:i'));
    }
}
