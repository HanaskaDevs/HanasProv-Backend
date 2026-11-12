<?php

namespace Tests\Feature\Reportes;

use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Services\HistorialEntregasService;
use Tests\TestCase;

/**
 * Reporte "Historial de proveedores" (12-nov-2026): a qué hora estaba
 * citado cada proveedor, a qué hora llegó y cuánto se atrasó.
 */
class HistorialProveedoresTest extends TestCase
{
    /**
     * Deja una entrega registrada ese día: el horario (citado a
     * $horaCitada) y el estado diario con el arribo real a $horaArribo.
     * Si $horaArribo es null, queda "sin arribo marcado".
     */
    private function entregaRegistrada(
        $empresa,
        $proveedor,
        string $fecha,
        string $horaCitada,
        ?string $horaArribo,
        ?string $horaRecepcion = null,
    ): HorarioEntregaEstadoDiario {
        $horario = HorarioEntregaProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)->first()
            ?? HorarioEntregaProveedor::create([
                'Id_Empresa' => $empresa->Id_Empresa,
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Clasificacion' => 'Fruver',
                'Dia_Entrega' => 'Lunes',
                'Hora_Llegada' => $horaCitada,
                'Activo' => 1,
                'Fecha_Creacion' => now(),
            ]);

        $horario->update(['Hora_Llegada' => $horaCitada]);

        return HorarioEntregaEstadoDiario::create([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => $fecha,
            'Hora_Arribo_Real' => $horaArribo ? "{$fecha} {$horaArribo}:00" : null,
            'Hora_Recepcion_Real' => $horaRecepcion ? "{$fecha} {$horaRecepcion}:00" : null,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function test_calcula_el_atraso_contra_la_hora_citada(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        // Citado 11:00, llegó 11:05 -> 5 minutos tarde (el caso del pedido).
        $this->entregaRegistrada($empresa, $proveedor, now()->toDateString(), '11:00', '11:05');

        $datos = $this->getJson('/api/reportes/historial-proveedores', $this->cabecerasComo($compras, $empresa))
            ->assertSuccessful()
            ->json();

        $entrega = collect($datos['entregas'])->firstWhere('id_proveedor', $proveedor->Id_Proveedor);

        $this->assertNotNull($entrega, 'La entrega tiene que aparecer en el historial.');
        $this->assertSame('11:00', $entrega['hora_programada']);
        $this->assertSame('11:05', $entrega['hora_arribo']);
        $this->assertSame(5, $entrega['minutos_atraso']);
        $this->assertSame('Arribo', $entrega['estado_final']);
    }

    public function test_llegar_antes_de_la_hora_da_atraso_negativo(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->entregaRegistrada($empresa, $proveedor, now()->toDateString(), '11:00', '10:50');

        $datos = $this->getJson('/api/reportes/historial-proveedores', $this->cabecerasComo($compras, $empresa))
            ->assertSuccessful()
            ->json();

        $entrega = collect($datos['entregas'])->firstWhere('id_proveedor', $proveedor->Id_Proveedor);
        $this->assertSame(-10, $entrega['minutos_atraso']);
    }

    public function test_el_resumen_por_proveedor_separa_a_tiempo_de_tarde(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $hoy = now();
        $this->entregaRegistrada($empresa, $proveedor, $hoy->toDateString(), '11:00', '11:20');
        $this->entregaRegistrada($empresa, $proveedor, $hoy->copy()->subDay()->toDateString(), '11:00', '10:58');
        // Sin arribo marcado: no entra en los promedios pero se cuenta.
        $this->entregaRegistrada($empresa, $proveedor, $hoy->copy()->subDays(2)->toDateString(), '11:00', null);

        $datos = $this->getJson('/api/reportes/historial-proveedores', $this->cabecerasComo($compras, $empresa))
            ->assertSuccessful()
            ->json();

        $resumen = collect($datos['por_proveedor'])->firstWhere('id_proveedor', $proveedor->Id_Proveedor);

        $this->assertSame(3, $resumen['entregas']);
        $this->assertSame(1, $resumen['tarde']);
        $this->assertSame(1, $resumen['a_tiempo']);
        $this->assertSame(1, $resumen['sin_arribo_marcado']);
        // (20 + (-2)) / 2 = 9
        $this->assertSame(9, $resumen['atraso_promedio']);
        $this->assertSame(20, $resumen['atraso_maximo']);
    }

    public function test_el_rango_se_acota_a_tres_meses(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $datos = $this->getJson(
            '/api/reportes/historial-proveedores?desde=' . now()->subYears(2)->toDateString() . '&hasta=' . now()->toDateString(),
            $this->cabecerasComo($compras, $empresa)
        )->assertSuccessful()->json();

        $dias = now()->parse($datos['desde'])->diffInDays(now()->parse($datos['hasta'])) + 1;

        $this->assertLessThanOrEqual(HistorialEntregasService::MAX_DIAS, $dias);
        $this->assertSame(HistorialEntregasService::MAX_DIAS, $datos['max_dias']);
    }

    public function test_el_filtro_por_fecha_deja_fuera_lo_anterior(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $vieja = now()->subDays(40);
        $this->entregaRegistrada($empresa, $proveedor, $vieja->toDateString(), '11:00', '11:30');

        $ultimaSemana = $this->getJson(
            '/api/reportes/historial-proveedores?desde=' . now()->subDays(6)->toDateString(),
            $this->cabecerasComo($compras, $empresa)
        )->assertSuccessful()->json();

        $this->assertNull(collect($ultimaSemana['entregas'])->firstWhere('id_proveedor', $proveedor->Id_Proveedor));

        $tresMeses = $this->getJson(
            '/api/reportes/historial-proveedores?desde=' . now()->subDays(80)->toDateString(),
            $this->cabecerasComo($compras, $empresa)
        )->assertSuccessful()->json();

        $this->assertNotNull(collect($tresMeses['entregas'])->firstWhere('id_proveedor', $proveedor->Id_Proveedor));
    }

    public function test_solo_entran_las_entregas_de_la_empresa_activa(): void
    {
        $empresa = $this->crearEmpresa();
        $otra = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $ajeno] = $this->crearProveedorConUsuario($otra);

        $this->entregaRegistrada($otra, $ajeno, now()->toDateString(), '11:00', '11:45');

        $datos = $this->getJson('/api/reportes/historial-proveedores', $this->cabecerasComo($compras, $empresa))
            ->assertSuccessful()
            ->json();

        $this->assertNull(collect($datos['entregas'])->firstWhere('id_proveedor', $ajeno->Id_Proveedor));
    }

    /**
     * Sistemas, Admin y Compras (12-nov-2026). Calidad lo tuvo unas horas
     * ese mismo día y salió a propósito.
     */
    public function test_calidad_y_el_proveedor_no_entran(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [$usuarioProveedor] = $this->crearProveedorConUsuario($empresa);

        $this->getJson('/api/reportes/historial-proveedores', $this->cabecerasComo($calidad, $empresa))
            ->assertForbidden();

        $this->getJson('/api/reportes/historial-proveedores', $this->cabecerasComo($usuarioProveedor, $empresa))
            ->assertForbidden();
    }

    public function test_sistemas_admin_y_compras_si_entran(): void
    {
        $empresa = $this->crearEmpresa();

        foreach (['Sistemas', 'Admin', 'Compras'] as $rol) {
            $usuario = $this->crearUsuarioInterno($empresa, $rol);

            $this->getJson('/api/reportes/historial-proveedores', $this->cabecerasComo($usuario, $empresa))
                ->assertSuccessful();
        }
    }

    /**
     * La espera es de la llegada a la recepción REAL (hora de SIGH), y la
     * salida es la programada en el calendario: el portal no registra la
     * hora real en que se va el camión.
     */
    public function test_muestra_la_espera_hasta_la_recepcion_y_la_salida_programada(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $estado = $this->entregaRegistrada($empresa, $proveedor, now()->toDateString(), '05:00', '05:04', '05:45');
        $estado->horario->update(['Hora_Salida' => '07:00']);

        $datos = $this->getJson('/api/reportes/historial-proveedores', $this->cabecerasComo($compras, $empresa))
            ->assertSuccessful()
            ->json();

        $entrega = collect($datos['entregas'])->firstWhere('id_proveedor', $proveedor->Id_Proveedor);
        $this->assertSame('05:45', $entrega['hora_recepcion']);
        $this->assertSame(41, $entrega['minutos_espera']);
        $this->assertSame('07:00', $entrega['hora_salida']);

        $resumen = collect($datos['por_proveedor'])->firstWhere('id_proveedor', $proveedor->Id_Proveedor);
        $this->assertSame(41, $resumen['espera_promedio']);
    }
}
