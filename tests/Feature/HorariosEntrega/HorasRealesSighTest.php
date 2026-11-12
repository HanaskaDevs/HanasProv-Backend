<?php

namespace Tests\Feature\HorariosEntrega;

use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Services\SincronizacionSighService;
use Carbon\CarbonInterface;
use Tests\TestCase;

/**
 * Recepción y Recibido reales desde WMS + SIGH (12-nov-2026).
 *
 * Recepción = primer producto escaneado en WMS (tmpRecepcion) ese día, de
 * cualquiera de los pedidos del proveedor. Recibido = cierre del último de
 * esos pedidos (ingreso en SIGH.DocumentoInv), solo cuando todos están
 * cerrados. Antes el portal guardaba el cierre como "recepción" y las dos
 * horas salían iguales.
 *
 * En las pruebas no hay conexión a SIGH/WMS: se reemplaza el service por
 * uno falso que devuelve, por pedido, el inicio y el cierre que cada prueba
 * necesita. resumirEntrega() es el real.
 */
class HorasRealesSighTest extends TestCase
{
    /**
     * SIGH/WMS falso.
     *
     * $documentosDelProveedor es [Id_Proveedor => [docs]]: SOLO el
     * proveedor de la prueba tiene pedidos. Las pruebas corren contra la
     * base del .env con rollback, y el job recorre todas las entregas del
     * día, también las reales: si el falso les diera documentos, el job las
     * actualizaría (y las bloquearía) mientras dura la prueba.
     *
     * $recepciones es [doc => ['inicio' => 'Y-m-d H:i:s'|null, 'cierre' => ...]].
     */
    private function sighFalso(array $documentosDelProveedor, array $recepciones, bool $falla = false): void
    {
        $falso = new class($documentosDelProveedor, $recepciones, $falla) extends SincronizacionSighService
        {
            public function __construct(
                private array $docs,
                private array $recepciones,
                private bool $falla,
            ) {
            }

            public function documentosBcDelProveedor(int $idEmpresa, int $idProveedor, ?CarbonInterface $fecha = null): array
            {
                return $this->docs[$idProveedor] ?? [];
            }

            public function recepcionesEnWms(array $nrosDocumentoBc, CarbonInterface $dia, bool $lanzarSiFalla = false): array
            {
                if ($this->falla) {
                    if ($lanzarSiFalla) {
                        throw new \RuntimeException('SIGH no responde');
                    }

                    return [];
                }

                return array_intersect_key($this->recepciones, array_flip($nrosDocumentoBc));
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
            'Hora_Arribo_Real' => $horaArribo ? "{$fecha} {$horaArribo}:00" : null,
            'Fecha_Creacion' => now(),
            ...$extra,
        ]);
    }

    private function sincronizar(): void
    {
        $this->artisan('horarios-entrega:sincronizar-sigh')->assertSuccessful();
    }

    private function hora(?CarbonInterface $valor): ?string
    {
        return $valor?->format('H:i');
    }

    // ------------------------------------------------------------------
    // El job de cada minuto
    // ------------------------------------------------------------------

    public function test_el_primer_escaneo_pone_la_entrega_en_recepcion_con_esa_hora(): void
    {
        $estado = $this->entregaConArribo('05:18');
        $hoy = now()->toDateString();
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-1']], ['CP-PD-1' => ['inicio' => "{$hoy} 05:46:52", 'cierre' => null]]);

        $this->sincronizar();

        $estado->refresh();
        $this->assertSame('05:46', $this->hora($estado->Hora_Recepcion_Real));
        $this->assertNull($estado->Hora_Entregado_Real, 'Sin cierre en SIGH todavía no está recibido.');
        $this->assertSame('CP-PD-1', $estado->Nro_Documento_Bc);
    }

    public function test_al_cerrarse_la_recepcion_queda_recibido_con_la_hora_del_cierre(): void
    {
        // Caso real del 12-nov (FLORES CALO): llegó 05:18, primer escaneo
        // 05:46, cierre 10:18. Antes las dos columnas decían 10:18.
        $estado = $this->entregaConArribo('05:18');
        $hoy = now()->toDateString();
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-1']], ['CP-PD-1' => ['inicio' => "{$hoy} 05:46:52", 'cierre' => "{$hoy} 10:18:04"]]);

        $this->sincronizar();

        $estado->refresh();
        $this->assertSame('05:46', $this->hora($estado->Hora_Recepcion_Real));
        $this->assertSame('10:18', $this->hora($estado->Hora_Entregado_Real));
        $this->assertNull($estado->Marcado_Entregado_Por);
    }

    public function test_varios_pedidos_primer_escaneo_y_recibido_solo_cuando_cierran_todos(): void
    {
        $estado = $this->entregaConArribo('08:20');
        $prov = $estado->horario->Id_Proveedor;
        $hoy = now()->toDateString();

        // A cerrado, B todavía abierto -> sigue en recepción.
        $this->sighFalso([$prov => ['CP-PD-A', 'CP-PD-B']], [
            'CP-PD-A' => ['inicio' => "{$hoy} 08:31:13", 'cierre' => "{$hoy} 08:32:35"],
            'CP-PD-B' => ['inicio' => "{$hoy} 08:34:05", 'cierre' => null],
        ]);
        $this->sincronizar();

        $estado->refresh();
        $this->assertSame('08:31', $this->hora($estado->Hora_Recepcion_Real));
        $this->assertNull($estado->Hora_Entregado_Real);
        $this->assertSame('CP-PD-A', $estado->Nro_Documento_Bc);

        // Se cierra B -> recibido con la hora del último cierre.
        $this->sighFalso([$prov => ['CP-PD-A', 'CP-PD-B']], [
            'CP-PD-A' => ['inicio' => "{$hoy} 08:31:13", 'cierre' => "{$hoy} 08:32:35"],
            'CP-PD-B' => ['inicio' => "{$hoy} 08:34:05", 'cierre' => "{$hoy} 08:35:07"],
        ]);
        $this->sincronizar();

        $estado->refresh();
        $this->assertSame('08:31', $this->hora($estado->Hora_Recepcion_Real));
        $this->assertSame('08:35', $this->hora($estado->Hora_Entregado_Real));
    }

    public function test_si_empieza_otro_pedido_despues_del_cierre_vuelve_a_recepcion(): void
    {
        $hoy = now()->toDateString();
        $estado = $this->entregaConArribo('08:20', [
            'Hora_Recepcion_Real' => "{$hoy} 08:31:13",
            'Hora_Entregado_Real' => "{$hoy} 08:32:35",
            'Nro_Documento_Bc' => 'CP-PD-A',
        ]);
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-A', 'CP-PD-B']], [
            'CP-PD-A' => ['inicio' => "{$hoy} 08:31:13", 'cierre' => "{$hoy} 08:32:35"],
            'CP-PD-B' => ['inicio' => "{$hoy} 09:10:00", 'cierre' => null],
        ]);

        $this->sincronizar();

        $estado->refresh();
        $this->assertSame('08:31', $this->hora($estado->Hora_Recepcion_Real));
        $this->assertNull($estado->Hora_Entregado_Real);
    }

    public function test_un_pedido_cerrado_sin_escaneos_cuenta_desde_el_cierre(): void
    {
        $estado = $this->entregaConArribo('09:00');
        $hoy = now()->toDateString();
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-1']], ['CP-PD-1' => ['inicio' => null, 'cierre' => "{$hoy} 09:30:00"]]);

        $this->sincronizar();

        $estado->refresh();
        $this->assertSame('09:30', $this->hora($estado->Hora_Recepcion_Real));
        $this->assertSame('09:30', $this->hora($estado->Hora_Entregado_Real));
    }

    public function test_sin_recepcion_ese_dia_no_marca_ni_borra_nada(): void
    {
        $hoy = now()->toDateString();
        $sinNada = $this->entregaConArribo('09:00');
        $conMarca = $this->entregaConArribo('09:00', ['Hora_Recepcion_Real' => "{$hoy} 09:20:00", 'Nro_Documento_Bc' => 'CP-PD-X']);
        $this->sighFalso([
            $sinNada->horario->Id_Proveedor => ['CP-PD-1'],
            $conMarca->horario->Id_Proveedor => ['CP-PD-X'],
        ], []);

        $this->sincronizar();

        $this->assertNull($sinNada->fresh()->Hora_Recepcion_Real);
        // Borrar marcas viejas es trabajo de la corrección (que simula
        // primero), no del job de cada minuto.
        $this->assertSame('09:20', $this->hora($conMarca->fresh()->Hora_Recepcion_Real));
    }

    public function test_el_job_no_toca_las_marcas_manuales(): void
    {
        $hoy = now()->toDateString();
        $usuario = $this->crearUsuarioInterno($this->crearEmpresa(), 'Compras');
        $estado = $this->entregaConArribo('05:04', [
            'Hora_Entregado_Real' => "{$hoy} 07:30:00",
            'Marcado_Entregado_Por' => $usuario->Id_Usuario,
        ]);
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-1']], ['CP-PD-1' => ['inicio' => "{$hoy} 05:46:00", 'cierre' => "{$hoy} 06:00:00"]]);

        $this->sincronizar();

        $estado->refresh();
        $this->assertNull($estado->Hora_Recepcion_Real);
        $this->assertSame('07:30', $this->hora($estado->Hora_Entregado_Real));
    }

    public function test_si_sigh_falla_el_job_no_revienta_ni_toca_nada(): void
    {
        $hoy = now()->toDateString();
        $estado = $this->entregaConArribo('05:04', ['Hora_Recepcion_Real' => "{$hoy} 05:46:00", 'Nro_Documento_Bc' => 'CP-PD-1']);
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-1']], [], falla: true);

        $this->sincronizar();

        $this->assertSame('05:46', $this->hora($estado->fresh()->Hora_Recepcion_Real));
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

    /** La entrega del 12-nov tal como la dejó la versión anterior: recepción = recibido = cierre. */
    private function entregaConCierreComoRecepcion(): HorarioEntregaEstadoDiario
    {
        $estado = $this->entregaConArribo('05:18', [
            'Hora_Recepcion_Real' => self::DIA . ' 10:18:04',
            'Hora_Entregado_Real' => self::DIA . ' 10:18:04',
            'Nro_Documento_Bc' => 'CP-PD-1',
        ], self::DIA);
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-1']], [
            'CP-PD-1' => ['inicio' => self::DIA . ' 05:46:52', 'cierre' => self::DIA . ' 10:18:04'],
        ]);

        return $estado;
    }

    public function test_la_correccion_por_defecto_solo_simula(): void
    {
        $estado = $this->entregaConCierreComoRecepcion();

        $this->corregir()->assertSuccessful();

        $this->assertSame('10:18', $this->hora($estado->fresh()->Hora_Recepcion_Real));
    }

    public function test_con_aplicar_separa_recepcion_y_recibido_y_se_puede_restaurar(): void
    {
        $estado = $this->entregaConCierreComoRecepcion();
        $antes = $this->respaldosActuales();

        $this->corregir(['--aplicar' => true])->assertSuccessful();

        $estado->refresh();
        $this->assertSame('05:46', $this->hora($estado->Hora_Recepcion_Real));
        $this->assertSame('10:18', $this->hora($estado->Hora_Entregado_Real));

        // Dejó un respaldo nuevo, y con él se vuelve a como estaba.
        $nuevos = array_values(array_diff($this->respaldosActuales(), $antes));
        $this->assertCount(1, $nuevos);

        $this->artisan('horarios-entrega:corregir-horas-sigh', ['--restaurar' => $nuevos[0]])->assertSuccessful();
        $this->assertSame('10:18', $this->hora($estado->fresh()->Hora_Recepcion_Real));

        $this->borrarRespaldosNuevos($antes);
    }

    public function test_completa_una_entrega_con_arribo_que_no_tenia_nada(): void
    {
        $estado = $this->entregaConArribo('09:00', [], self::DIA);
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-7']], [
            'CP-PD-7' => ['inicio' => self::DIA . ' 09:24:28', 'cierre' => self::DIA . ' 10:11:18'],
        ]);
        $antes = $this->respaldosActuales();

        $this->corregir(['--aplicar' => true])->assertSuccessful();

        $estado->refresh();
        $this->assertSame('09:24', $this->hora($estado->Hora_Recepcion_Real));
        $this->assertSame('10:11', $this->hora($estado->Hora_Entregado_Real));
        $this->assertSame('CP-PD-7', $estado->Nro_Documento_Bc);

        $this->borrarRespaldosNuevos($antes);
    }

    public function test_una_marca_falsa_se_quita(): void
    {
        $estado = $this->entregaConArribo('11:50', [
            'Hora_Recepcion_Real' => self::DIA . ' 11:51:00',
            'Nro_Documento_Bc' => 'CP-PD-SIN-RECIBIR',
        ], self::DIA);
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-SIN-RECIBIR']], []);
        $antes = $this->respaldosActuales();

        $this->corregir(['--aplicar' => true])->assertSuccessful();

        $estado->refresh();
        $this->assertNull($estado->Hora_Recepcion_Real);
        $this->assertNull($estado->Nro_Documento_Bc);

        $this->borrarRespaldosNuevos($antes);
    }

    public function test_si_sigh_falla_la_correccion_aborta_sin_tocar_nada(): void
    {
        $estado = $this->entregaConArribo('05:18', [
            'Hora_Recepcion_Real' => self::DIA . ' 10:18:04',
            'Nro_Documento_Bc' => 'CP-PD-1',
        ], self::DIA);
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-1']], [], falla: true);

        // Sin el modo estricto, un fallo de SIGH se leería como "no hubo
        // recepción" y borraría horas buenas.
        $this->corregir(['--aplicar' => true])->assertFailed();

        $this->assertSame('10:18', $this->hora($estado->fresh()->Hora_Recepcion_Real));
    }

    public function test_la_correccion_no_toca_las_marcas_manuales(): void
    {
        $usuario = $this->crearUsuarioInterno($this->crearEmpresa(), 'Guardia');
        $estado = $this->entregaConArribo('05:04', [
            'Hora_Recepcion_Real' => self::DIA . ' 07:00:00',
            'Hora_Entregado_Real' => self::DIA . ' 07:30:00',
            'Marcado_Entregado_Por' => $usuario->Id_Usuario,
            'Nro_Documento_Bc' => 'CP-PD-1',
        ], self::DIA);
        $this->sighFalso([$estado->horario->Id_Proveedor => ['CP-PD-1']], []);

        $this->corregir(['--aplicar' => true])->assertSuccessful();

        $this->assertSame('07:30', $this->hora($estado->fresh()->Hora_Entregado_Real));
    }
}
