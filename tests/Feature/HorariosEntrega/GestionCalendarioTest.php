<?php

namespace Tests\Feature\HorariosEntrega;

use App\Modules\Horarios_Entrega\Services\HorarioEntregaService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * Quién gestiona (crea, edita, borra) el calendario de horarios: Sistemas,
 * Admin, Compras y Calidad (Calidad desde el 16-oct-2026). Guardia solo
 * marca arribos.
 */
class GestionCalendarioTest extends TestCase
{
    public function test_calidad_gestiona_el_calendario(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        // El desplegable de proveedores del modal "Gestionar calendario"
        // pasa por la misma validación que crear, editar y borrar.
        $this->getJson('/api/horarios-entrega/proveedores', $this->cabecerasComo($calidad, $empresa))
            ->assertOk();

        app(HorarioEntregaService::class)->verificarAccesoGestion($calidad, $empresa->Id_Empresa);
        $this->addToAssertionCount(1);
    }

    public function test_guardia_no_gestiona_el_calendario(): void
    {
        $empresa = $this->crearEmpresa();
        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');

        $this->expectException(AccessDeniedHttpException::class);

        app(HorarioEntregaService::class)->verificarAccesoGestion($guardia, $empresa->Id_Empresa);
    }
}
