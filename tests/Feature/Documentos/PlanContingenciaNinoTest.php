<?php

namespace Tests\Feature\Documentos;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\CalificacionProveedorService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Plan de contingencia contra el fenómeno del Niño" (16-oct-2026):
 * opcional, sin caducidad, informativo (no se califica) y se puede subir
 * en cualquier momento, aunque la documentación ya esté registrada.
 */
class PlanContingenciaNinoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('repositorio_proveedores');
    }

    private function tipoPlan(): TipoDocumento
    {
        $tipo = TipoDocumento::where('Codigo_Archivo', TipoDocumento::CODIGO_PLAN_CONTINGENCIA_NINO)->first();

        $this->assertNotNull($tipo, 'Falta el tipo PCNINO. Corré: php artisan migrate');

        return $tipo;
    }

    private function subirPlan(Usuario $usuario, Empresa $empresa)
    {
        return $this->post(
            "/api/mi-documentos/{$this->tipoPlan()->Id_Tipo_Documento}",
            ['archivo' => UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf')],
            $this->cabecerasComo($usuario, $empresa)
        );
    }

    private function planDe(Proveedor $proveedor): ?DocumentoProveedor
    {
        return DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Id_Tipo_Documento', $this->tipoPlan()->Id_Tipo_Documento)
            ->where('Activo', 1)
            ->first();
    }

    public function test_el_tipo_es_opcional_y_sin_caducidad(): void
    {
        $tipo = $this->tipoPlan();

        $this->assertSame('Plan de contingencia contra el fenómeno del Niño', $tipo->Nombre_Documento);
        $this->assertFalse($tipo->Obligatorio);
        $this->assertFalse($tipo->Requiere_Fecha_Caducidad);
        $this->assertTrue($tipo->Activo);
    }

    public function test_el_checklist_lo_marca_como_informativo_con_su_codigo(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa);

        $plan = collect($this->getJson('/api/mi-documentos', $this->cabecerasComo($usuario, $empresa))->json('documentos'))
            ->firstWhere('codigo', TipoDocumento::CODIGO_PLAN_CONTINGENCIA_NINO);

        $this->assertNotNull($plan);
        $this->assertTrue($plan['informativo']);
        $this->assertFalse($plan['obligatorio']);
    }

    /** Lo que se pide es justamente que lo suban los que YA están dentro. */
    public function test_se_puede_subir_aunque_la_documentacion_ya_este_registrada(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $proveedor->forceFill(['Fecha_Registro_Documentacion' => now()])->save();

        $this->subirPlan($usuario, $empresa)->assertCreated();

        $this->assertNotNull($this->planDe($proveedor));
    }

    /** El bloqueo sigue intacto para cualquier otro documento. */
    public function test_otro_documento_sigue_bloqueado_despues_de_registrar(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $proveedor->forceFill(['Fecha_Registro_Documentacion' => now()])->save();

        $otro = TipoDocumento::where('Activo', 1)
            ->where('Codigo_Archivo', '<>', TipoDocumento::CODIGO_PLAN_CONTINGENCIA_NINO)
            ->where('Requiere_Fecha_Caducidad', 0)
            ->firstOrFail();

        $this->post(
            "/api/mi-documentos/{$otro->Id_Tipo_Documento}",
            ['archivo' => UploadedFile::fake()->create('otro.pdf', 100, 'application/pdf')],
            $this->cabecerasComo($usuario, $empresa)
        )->assertStatus(422);
    }

    public function test_volver_a_subirlo_reemplaza_al_anterior(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->subirPlan($usuario, $empresa)->assertCreated();
        $primero = $this->planDe($proveedor);
        $this->subirPlan($usuario, $empresa)->assertCreated();

        $this->assertNotSame($primero->Id_Documento_Proveedor, $this->planDe($proveedor)->Id_Documento_Proveedor);
        $this->assertSame(1, DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Id_Tipo_Documento', $this->tipoPlan()->Id_Tipo_Documento)
            ->where('Activo', 1)
            ->count());
    }

    /**
     * Nace resuelto: no queda "por calificar" en ningún contador, no frena
     * la aprobación y Calidad no lo puede calificar.
     */
    public function test_no_queda_por_calificar_y_no_se_puede_calificar(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->subirPlan($usuario, $empresa)->assertCreated();
        $plan = $this->planDe($proveedor);

        $this->assertNotNull($plan->Estado_Calificacion);

        $diagnostico = app(CalificacionProveedorService::class)->diagnosticarCondicionesAprobado($proveedor);
        $this->assertSame(0, $diagnostico['documentos_no_aprobados']);
        // Subir SOLO el plan no puede dar la documentación por aprobada.
        $this->assertSame(0, $diagnostico['total_documentos']);
        $this->assertFalse($diagnostico['documentacion_aprobada']);

        $this->postJson(
            "/api/proveedores/documentos-calificacion/{$plan->Id_Documento_Proveedor}",
            ['aprobado' => false, 'observacion' => 'No'],
            $this->cabecerasComo($calidad, $empresa)
        )->assertStatus(422);
    }

    public function test_el_resumen_cuenta_quien_lo_cargo_y_quien_no(): void
    {
        $empresa = $this->crearEmpresa();
        [$conPlan, $proveedorConPlan] = $this->crearProveedorConUsuario($empresa);
        [, $proveedorSinPlan] = $this->crearProveedorConUsuario($empresa);

        $this->subirPlan($conPlan, $empresa)->assertCreated();

        foreach (['Sistemas', 'Admin', 'Calidad'] as $rol) {
            $interno = $this->crearUsuarioInterno($empresa, $rol);

            $respuesta = $this->getJson('/api/reportes/plan-contingencia-nino', $this->cabecerasComo($interno, $empresa))
                ->assertOk();

            $this->assertSame(2, $respuesta->json('total_proveedores'), "Rol {$rol}");
            $this->assertSame(1, $respuesta->json('cargaron'));
            $this->assertSame($proveedorConPlan->Id_Proveedor, $respuesta->json('con_plan.0.id_proveedor'));
            $this->assertNotNull($respuesta->json('con_plan.0.id_documento_proveedor'));
            $this->assertSame($proveedorSinPlan->Id_Proveedor, $respuesta->json('sin_plan.0.id_proveedor'));
        }
    }

    public function test_compras_guardia_y_proveedor_no_ven_el_resumen(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor] = $this->crearProveedorConUsuario($empresa);

        foreach ([$this->crearUsuarioInterno($empresa, 'Compras'), $this->crearUsuarioInterno($empresa, 'Guardia'), $usuarioProveedor] as $usuario) {
            $this->getJson('/api/reportes/plan-contingencia-nino', $this->cabecerasComo($usuario, $empresa))
                ->assertForbidden();
        }
    }
}
