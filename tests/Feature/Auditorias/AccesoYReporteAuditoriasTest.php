<?php

namespace Tests\Feature\Auditorias;

use App\Models\Empresa;
use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Auditorias\Models\TipoAuditoria;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\Proveedor;
use Tests\TestCase;

/**
 * Auditorías y Calificación de Recepciones: quién entra (Sistemas, Admin y
 * Calidad; Admin volvió el 16-oct-2026) y el mini reporte de cada pantalla,
 * que muestra la ÚLTIMA nota finalizada de cada proveedor.
 */
class AccesoYReporteAuditoriasTest extends TestCase
{
    private function auditoria(Empresa $empresa, Proveedor $proveedor, Usuario $auditor, string $fecha, float $porcentaje, string $estado = 'Finalizada'): Auditoria
    {
        return Auditoria::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Tipo_Auditoria' => TipoAuditoria::firstOrFail()->Id_Tipo_Auditoria,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Usuario_Auditor' => $auditor->Id_Usuario,
            'Fecha_Auditoria' => $fecha,
            'Estado' => $estado,
            'Porcentaje_Cumplimiento' => $porcentaje,
            'Fecha_Creacion' => now(),
        ]);
    }

    private function recepcion(Empresa $empresa, Proveedor $proveedor, Usuario $auditor, string $fecha, float $porcentaje, string $estado = 'Finalizada'): CalificacionRecepcion
    {
        return CalificacionRecepcion::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Usuario_Auditor' => $auditor->Id_Usuario,
            'Fecha_Recepcion' => $fecha,
            'Estado' => $estado,
            'Puntaje_Total_Posible' => 200,
            'Puntaje_Obtenido' => $porcentaje * 2,
            'Porcentaje_Obtenido' => $porcentaje,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function test_admin_entra_a_auditorias_y_a_recepciones(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        $cabeceras = $this->cabecerasComo($admin, $empresa);

        $this->getJson('/api/auditorias/tipos', $cabeceras)->assertOk();
        $this->getJson('/api/auditorias/proveedores', $cabeceras)->assertOk();
        $this->getJson('/api/auditorias/resumen', $cabeceras)->assertOk();
        $this->getJson('/api/calificacion-recepciones/proveedores', $cabeceras)->assertOk();
        $this->getJson('/api/calificacion-recepciones/historial', $cabeceras)->assertOk();
    }

    /** Acceso completo es también poder calificar, no solo mirar. */
    public function test_admin_puede_iniciar_una_auditoria_y_una_calificacion_de_recepcion(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $cabeceras = $this->cabecerasComo($admin, $empresa);

        $this->postJson('/api/auditorias/iniciar', [
            'id_tipo_auditoria' => TipoAuditoria::where('Activo', true)->firstOrFail()->Id_Tipo_Auditoria,
            'id_proveedor' => $proveedor->Id_Proveedor,
        ], $cabeceras)->assertSuccessful();

        $this->postJson('/api/calificacion-recepciones/iniciar', [
            'id_proveedor' => $proveedor->Id_Proveedor,
        ], $cabeceras)->assertSuccessful();
    }

    public function test_compras_y_proveedor_siguen_sin_acceso(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [$usuarioProveedor] = $this->crearProveedorConUsuario($empresa);

        foreach ([$compras, $usuarioProveedor] as $usuario) {
            $cabeceras = $this->cabecerasComo($usuario, $empresa);

            $this->getJson('/api/auditorias/reporte', $cabeceras)->assertForbidden();
            $this->getJson('/api/calificacion-recepciones/reporte', $cabeceras)->assertForbidden();
        }
    }

    /**
     * Una fila por proveedor con su ÚLTIMA auditoría finalizada: los
     * borradores no cuentan, y la cantidad es la de finalizadas.
     */
    public function test_el_reporte_de_auditorias_muestra_la_ultima_nota_de_cada_proveedor(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $auditado] = $this->crearProveedorConUsuario($empresa);
        [, $sinAuditar] = $this->crearProveedorConUsuario($empresa);

        $this->auditoria($empresa, $auditado, $calidad, now()->subMonths(6)->toDateString(), 40);
        $this->auditoria($empresa, $auditado, $calidad, now()->subDays(5)->toDateString(), 85.5);
        $this->auditoria($empresa, $auditado, $calidad, now()->toDateString(), 10, 'Borrador');

        foreach (['Sistemas', 'Admin', 'Calidad'] as $rol) {
            $interno = $this->crearUsuarioInterno($empresa, $rol);

            $filas = $this->getJson('/api/auditorias/reporte', $this->cabecerasComo($interno, $empresa))
                ->assertOk()
                ->json();

            $this->assertCount(1, $filas, "Rol {$rol}");
            $this->assertSame($auditado->Id_Proveedor, $filas[0]['id_proveedor']);
            $this->assertEquals(85.5, $filas[0]['porcentaje']);
            $this->assertSame(2, $filas[0]['cantidad']);
            $this->assertSame($calidad->Nombre_Completo, $filas[0]['auditor']);
            $this->assertNotContains($sinAuditar->Id_Proveedor, array_column($filas, 'id_proveedor'));
        }
    }

    public function test_el_reporte_de_recepciones_muestra_la_ultima_nota_de_cada_proveedor(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->recepcion($empresa, $proveedor, $calidad, now()->subMonths(4)->toDateString(), 60);
        $this->recepcion($empresa, $proveedor, $calidad, now()->subDays(2)->toDateString(), 92);

        $filas = $this->getJson('/api/calificacion-recepciones/reporte', $this->cabecerasComo($calidad, $empresa))
            ->assertOk()
            ->json();

        $this->assertCount(1, $filas);
        $this->assertEquals(92, $filas[0]['porcentaje']);
        $this->assertSame(2, $filas[0]['cantidad']);
        $this->assertSame(now()->subDays(2)->toDateString(), $filas[0]['fecha']);
    }

    public function test_el_reporte_no_mezcla_empresas(): void
    {
        $empresa = $this->crearEmpresa();
        $otra = $this->crearEmpresa();
        $calidadOtra = $this->crearUsuarioInterno($otra, 'Calidad');
        [, $ajeno] = $this->crearProveedorConUsuario($otra);
        $this->auditoria($otra, $ajeno, $calidadOtra, now()->toDateString(), 70);
        $this->recepcion($otra, $ajeno, $calidadOtra, now()->toDateString(), 70);

        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Calidad'), $empresa);

        $this->getJson('/api/auditorias/reporte', $cabeceras)->assertOk()->assertExactJson([]);
        $this->getJson('/api/calificacion-recepciones/reporte', $cabeceras)->assertOk()->assertExactJson([]);
    }
}
