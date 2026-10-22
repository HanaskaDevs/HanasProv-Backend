<?php

namespace Tests\Feature\Proveedores;

use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Models\TipoAuditoria;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Responsables\Models\Responsable;
use App\Modules\Responsables\Models\ResponsableProveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * En todo el portal al proveedor se lo nombra por su RAZÓN SOCIAL, no por
 * el nombre comercial ni por la persona dueña del usuario (23-oct-2026).
 * Ver Proveedor::nombreVisible.
 *
 * Todos los casos usan un proveedor con nombre comercial DISTINTO de la
 * razón social: si alguna pantalla vuelve a preferir el comercial, falla.
 */
class NombreVisibleProveedorTest extends TestCase
{
    private const RAZON_SOCIAL = 'DISTRIBUIDORA EJEMPLO S.A.';
    private const COMERCIAL = 'EJEMPLITO';

    public function test_el_nombre_visible_es_la_razon_social(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => self::RAZON_SOCIAL, 'Nombre_Comercial' => self::COMERCIAL]);

        $this->assertSame(self::RAZON_SOCIAL, $proveedor->nombreVisible());
        $this->assertSame(Proveedor::NOMBRE_SIN_FICHA, Proveedor::nombreVisibleDe(null));
    }

    public function test_el_calendario_de_horarios_usa_la_razon_social(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => self::RAZON_SOCIAL, 'Nombre_Comercial' => self::COMERCIAL]);
        HorarioEntregaProveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => HorarioEntregaProveedor::PERECIBLES,
            'Dia_Entrega' => 'Lunes',
            'Hora_Llegada' => '08:00',
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $this->getJson('/api/horarios-entrega', $cabeceras)->assertOk()->assertJsonPath('0.nombre_proveedor', self::RAZON_SOCIAL);

        $selector = collect($this->getJson('/api/horarios-entrega/proveedores', $cabeceras)->json())->firstWhere('id_proveedor', $proveedor->Id_Proveedor);
        $this->assertSame(self::RAZON_SOCIAL, $selector['nombre']);
    }

    public function test_el_reporte_de_auditorias_usa_la_razon_social(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => self::RAZON_SOCIAL, 'Nombre_Comercial' => self::COMERCIAL]);
        Auditoria::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Tipo_Auditoria' => TipoAuditoria::firstOrFail()->Id_Tipo_Auditoria,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Usuario_Auditor' => $calidad->Id_Usuario,
            'Fecha_Auditoria' => now()->toDateString(),
            'Estado' => 'Finalizada',
            'Porcentaje_Cumplimiento' => 90,
            'Fecha_Creacion' => now(),
        ]);

        $this->getJson('/api/auditorias/reporte', $this->cabecerasComo($calidad, $empresa))
            ->assertOk()
            ->assertJsonPath('0.proveedor', self::RAZON_SOCIAL);
    }

    /** Responsables: la razón social del portal gana sobre el nombre que tiene en BC. */
    public function test_responsables_usa_la_razon_social_del_portal(): void
    {
        $empresa = $this->crearEmpresa(['Empresa_BC' => 'test_'.Str::lower(Str::random(6))]);
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Razon_Social' => self::RAZON_SOCIAL]);
        $codigo = 'PROV-T'.Str::upper(Str::random(5));

        DB::table('BC_Ficha_Proveedor')->insert([
            'Empresa' => $empresa->Empresa_BC,
            'Nro_Proveedor' => $codigo,
            'Nombre' => 'NOMBRE VIEJO EN BC',
            'Nro_Identificacion' => $proveedor->Ruc,
            'Fecha_Registro' => now(),
        ]);
        $responsable = Responsable::create(['Nombre' => 'Ana', 'Correo' => 'resp_'.Str::lower(Str::random(8)).'@hanaska.com', 'Activo' => true, 'Fecha_Creacion' => now()]);
        ResponsableProveedor::create(['Id_Empresa' => $empresa->Id_Empresa, 'Nro_Proveedor_BC' => $codigo, 'Id_Responsable' => $responsable->Id_Responsable, 'Fecha_Creacion' => now()]);

        $fila = collect($this->getJson('/api/responsables/asignaciones', $this->cabecerasComo($sistemas, $empresa))->assertOk()->json())
            ->firstWhere('nro_proveedor_bc', $codigo);

        $this->assertSame(self::RAZON_SOCIAL, $fila['nombre_proveedor']);
        $this->assertSame('NOMBRE VIEJO EN BC', $fila['nombre_en_bc']);
    }
}
