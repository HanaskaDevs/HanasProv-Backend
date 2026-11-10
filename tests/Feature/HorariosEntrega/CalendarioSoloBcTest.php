<?php

namespace Tests\Feature\HorariosEntrega;

use App\Models\Empresa;
use App\Modules\Horarios_Entrega\Http\Middleware\TokenIntegracionBc;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Services\HorarioEntregaService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Desde el 10-nov-2026 el calendario de horarios se administra SOLO desde
 * Business Central (config portal.horarios_solo_bc, PORTAL_HORARIOS_SOLO_BC).
 * La pantalla del portal no puede crear, editar, dar de baja ni cargar por
 * Excel; la integración con BC sigue escribiendo; y leer el calendario
 * sigue funcionando.
 */
class CalendarioSoloBcTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.horarios_solo_bc' => true]);
        $this->empresa = $this->crearEmpresa(['Empresa_BC' => 'bc_'.Str::lower(Str::random(10))]);
    }

    private function horario(): HorarioEntregaProveedor
    {
        [, $proveedor] = $this->crearProveedorConUsuario($this->empresa);

        return HorarioEntregaProveedor::create([
            'Id_Empresa' => $this->empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => HorarioEntregaProveedor::PERECIBLES,
            'Dia_Entrega' => 'Lunes',
            'Hora_Llegada' => '08:00',
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function test_la_pantalla_no_puede_crear_editar_borrar_ni_cargar(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $this->empresa);
        $horario = $this->horario();
        $id = $horario->Id_Horario_Entrega_Proveedor;

        $this->postJson('/api/horarios-entrega', ['clasificacion' => 'Perecibles'], $cabeceras)
            ->assertStatus(403)->assertJsonPath('message', HorarioEntregaService::MENSAJE_SOLO_BC);
        $this->putJson("/api/horarios-entrega/{$id}", ['hora_llegada' => '09:00'], $cabeceras)->assertStatus(403);
        $this->deleteJson("/api/horarios-entrega/{$id}", [], $cabeceras)->assertStatus(403);
        $this->postJson('/api/horarios-entrega/carga-masiva', ['identificador' => 'ruc', 'confirmar' => true, 'filas' => []], $cabeceras)
            ->assertStatus(403);

        $horario->refresh();
        $this->assertTrue((bool) $horario->Activo, 'El horario no se debe dar de baja.');
        $this->assertSame('08:00', $horario->Hora_Llegada, 'El horario no se debe modificar.');
    }

    public function test_leer_el_calendario_sigue_funcionando(): void
    {
        $compras = $this->crearUsuarioInterno($this->empresa, 'Compras');
        $this->horario();

        $this->getJson('/api/horarios-entrega?clasificacion=Perecibles', $this->cabecerasComo($compras, $this->empresa))
            ->assertOk()->assertJsonCount(1);
    }

    public function test_la_integracion_con_bc_sigue_escribiendo(): void
    {
        $usuario = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        config(['integraciones.bc.token' => 'token-prueba-solo-bc-0123456789', 'integraciones.bc.usuario' => $usuario->Email]);
        [, $proveedor] = $this->crearProveedorConUsuario($this->empresa);

        $this->postJson('/api/integraciones/bc/horarios-entrega/carga-masiva', [
            'empresa_bc' => trim($this->empresa->Empresa_BC),
            'identificador' => 'ruc',
            'confirmar' => true,
            'filas' => [[
                'clasificacion' => 'Fruver', 'dia_entrega' => 'Martes', 'identificador' => $proveedor->Ruc,
                'anden_puerta' => 'A', 'hora_llegada' => '07:00', 'tiempo_permanencia' => '30',
            ]],
        ], [TokenIntegracionBc::CABECERA => 'token-prueba-solo-bc-0123456789'])
            ->assertOk()->assertJsonPath('resumen.crear', 1);

        $this->assertTrue(HorarioEntregaProveedor::where('Id_Empresa', $this->empresa->Id_Empresa)->where('Clasificacion', 'Fruver')->where('Activo', 1)->exists());
    }

    public function test_con_el_interruptor_apagado_la_pantalla_vuelve_a_editar(): void
    {
        config(['portal.horarios_solo_bc' => false]);
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $horario = $this->horario();

        $this->deleteJson("/api/horarios-entrega/{$horario->Id_Horario_Entrega_Proveedor}", [], $this->cabecerasComo($sistemas, $this->empresa))
            ->assertOk();

        $this->assertFalse((bool) $horario->fresh()->Activo);
    }
}
