<?php

namespace Tests\Feature\Auditorias;

use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Models\TipoAuditoria;
use Tests\TestCase;

/**
 * Una auditoría nueva solo el día que el proveedor entrega (26-oct-2026):
 * en el calendario de horarios con el día de hoy Y con un pedido con
 * recepción esperada hoy.
 */
class AuditoriaEntregaDelDiaTest extends TestCase
{
    private function idTipo(): int
    {
        return (int) TipoAuditoria::where('Activo', true)->firstOrFail()->Id_Tipo_Auditoria;
    }

    public function test_no_se_inicia_una_auditoria_si_ese_dia_no_entrega(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $soloCalendario] = $this->crearProveedorConUsuario($empresa);
        [, $soloPedido] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $soloCalendario, pedido: false);
        $this->crearEntrega($empresa, $soloPedido, calendario: false);
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        $this->postJson('/api/auditorias/iniciar', ['id_tipo_auditoria' => $this->idTipo(), 'id_proveedor' => $soloCalendario->Id_Proveedor], $cabeceras)
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_proveedor');

        $this->postJson('/api/auditorias/iniciar', ['id_tipo_auditoria' => $this->idTipo(), 'id_proveedor' => $soloPedido->Id_Proveedor], $cabeceras)
            ->assertStatus(422);

        $this->assertSame(0, Auditoria::whereIn('Id_Proveedor', [$soloCalendario->Id_Proveedor, $soloPedido->Id_Proveedor])->count());
    }

    public function test_con_calendario_y_pedido_hoy_se_inicia(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);

        $this->postJson('/api/auditorias/iniciar', [
            'id_tipo_auditoria' => $this->idTipo(),
            'id_proveedor' => $proveedor->Id_Proveedor,
        ], $this->cabecerasComo($calidad, $empresa))->assertSuccessful();

        $this->assertSame(1, Auditoria::where('Id_Proveedor', $proveedor->Id_Proveedor)->count());
    }

    /** El borrador ya empezado se retoma aunque hoy no entregue: no se pierde lo respondido. */
    public function test_un_borrador_ya_empezado_se_retoma_sin_entrega(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $borrador = Auditoria::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Tipo_Auditoria' => $this->idTipo(),
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Usuario_Auditor' => $calidad->Id_Usuario,
            'Fecha_Auditoria' => now()->subDays(3)->toDateString(),
            'Estado' => 'Borrador',
            'Fecha_Creacion' => now(),
        ]);

        $this->postJson('/api/auditorias/iniciar', [
            'id_tipo_auditoria' => $this->idTipo(),
            'id_proveedor' => $proveedor->Id_Proveedor,
        ], $this->cabecerasComo($calidad, $empresa))->assertSuccessful();

        $this->assertSame(1, Auditoria::where('Id_Proveedor', $proveedor->Id_Proveedor)->count());
        $this->assertNotNull($borrador->fresh());
    }

    public function test_la_lista_marca_y_pone_primero_a_quien_entrega_hoy(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $sinEntrega] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => 'AAA SIN ENTREGA']);
        [, $conEntrega] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => 'ZZZ CON ENTREGA']);
        $this->crearEntrega($empresa, $conEntrega);

        $filas = collect($this->getJson('/api/auditorias/proveedores', $this->cabecerasComo($calidad, $empresa))->assertOk()->json());

        $this->assertSame($conEntrega->Id_Proveedor, $filas->first()['id_proveedor']);
        $this->assertTrue($filas->firstWhere('id_proveedor', $conEntrega->Id_Proveedor)['tiene_entrega_hoy']);
        $this->assertFalse($filas->firstWhere('id_proveedor', $sinEntrega->Id_Proveedor)['tiene_entrega_hoy']);
    }
}
