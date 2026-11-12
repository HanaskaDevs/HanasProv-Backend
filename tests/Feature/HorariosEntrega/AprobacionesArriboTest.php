<?php

namespace Tests\Feature\HorariosEntrega;

use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Models\SolicitudAprobacionArribo;
use Tests\TestCase;

/**
 * Aprobaciones de arribo (12-nov-2026, pedido del usuario):
 *
 *  - el ítem del menú se le muestra a quien resuelve esos arribos, que es
 *    quien ya recibe los avisos (portal.email_aprobacion_arribo);
 *  - la bandeja lista SOLO las solicitudes del día.
 */
class AprobacionesArriboTest extends TestCase
{
    private function horarioDeHoy(int $idEmpresa, int $idProveedor): HorarioEntregaProveedor
    {
        return HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Id_Proveedor', $idProveedor)
            ->firstOrFail();
    }

    public function test_solo_el_correo_configurado_resuelve_aprobaciones_de_arribo(): void
    {
        $empresa = $this->crearEmpresa();
        $responsable = $this->crearUsuarioInterno($empresa, 'Calidad');
        $otro = $this->crearUsuarioInterno($empresa, 'Calidad');

        // Se configura con el correo del primero, escrito distinto a
        // propósito: la comparación no debe distinguir mayúsculas.
        config(['portal.email_aprobacion_arribo' => strtoupper($responsable->Email)]);

        $this->getJson('/api/auth/me', $this->cabecerasComo($responsable, $empresa))
            ->assertSuccessful()
            ->assertJsonPath('usuario.resuelve_aprobaciones_arribo', true);

        $this->getJson('/api/auth/me', $this->cabecerasComo($otro, $empresa))
            ->assertSuccessful()
            ->assertJsonPath('usuario.resuelve_aprobaciones_arribo', false);
    }

    public function test_sin_correo_configurado_no_se_le_muestra_a_nadie(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        config(['portal.email_aprobacion_arribo' => '']);

        $this->getJson('/api/auth/me', $this->cabecerasComo($calidad, $empresa))
            ->assertSuccessful()
            ->assertJsonPath('usuario.resuelve_aprobaciones_arribo', false);
    }

    public function test_las_aprobaciones_de_arribo_solo_muestran_las_de_hoy(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);
        $horario = $this->horarioDeHoy($empresa->Id_Empresa, $proveedor->Id_Proveedor);

        $deHoy = SolicitudAprobacionArribo::create([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => now()->toDateString(),
            'Solicitado_Por' => $guardia->Id_Usuario,
            'Fecha_Solicitud' => now(),
            'Estado' => 'Pendiente',
        ]);

        $deAyer = SolicitudAprobacionArribo::create([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => now()->subDay()->toDateString(),
            'Solicitado_Por' => $guardia->Id_Usuario,
            'Fecha_Solicitud' => now()->subDay(),
            'Estado' => 'Pendiente',
        ]);

        $respuesta = $this->getJson('/api/horarios-entrega/aprobaciones', $this->cabecerasComo($calidad, $empresa))
            ->assertSuccessful()
            ->json();

        $ids = array_column($respuesta, 'id_solicitud_aprobacion_arribo');
        $this->assertContains($deHoy->Id_Solicitud_Aprobacion_Arribo, $ids);
        $this->assertNotContains($deAyer->Id_Solicitud_Aprobacion_Arribo, $ids);

        // La de ayer no se borra ni cambia de estado: solo deja de listarse.
        $this->assertSame('Pendiente', $deAyer->fresh()->Estado);
    }

    public function test_compras_no_entra_a_la_bandeja_de_aprobaciones(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->getJson('/api/horarios-entrega/aprobaciones', $this->cabecerasComo($compras, $empresa))
            ->assertForbidden();
    }
}
