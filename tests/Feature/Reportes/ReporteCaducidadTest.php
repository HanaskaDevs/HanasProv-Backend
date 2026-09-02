<?php

namespace Tests\Feature\Reportes;

use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Documentos_Proveedor\Services\ReporteCaducidadService;
use Tests\TestCase;

/**
 * Reporte de caducidad de documentos: agrupa por urgencia y solo lo ven
 * Admin, Calidad y Sistemas.
 */
class ReporteCaducidadTest extends TestCase
{
    private function crearDocumento(int $idProveedor, int $diasParaVencer): DocumentoProveedor
    {
        // Un tipo cualquiera de los que ya existen en la base (los catálogos
        // no se crean por test, ver Tests\TestCase).
        $idTipo = TipoDocumento::where('Activo', 1)->value('Id_Tipo_Documento');

        // Documento_Proveedor.Id_Archivo es NOT NULL: hace falta un Archivo
        // aunque este reporte no lo use para nada.
        $archivo = Archivo::create([
            'Id_Proveedor' => $idProveedor,
            'Nombre_Original' => 'prueba.pdf',
            'Ruta_Almacenamiento' => 'pruebas/prueba-'.uniqid().'.pdf',
            'Hash_Archivo' => hash('sha256', uniqid('', true)),
            'Tipo_Mime' => 'application/pdf',
            'Tamano_Bytes' => 1024,
            'Categoria_Archivo' => 'prueba',
            // Id_Usuario_Carga es NOT NULL. Cualquier usuario sirve: este
            // reporte no lo mira, solo hace falta para poder insertar.
            'Id_Usuario_Carga' => \App\Modules\Auth\Models\Usuario::value('Id_Usuario'),
            'Fecha_Carga' => now(),
            'Activo' => 1,
        ]);

        return DocumentoProveedor::create([
            'Id_Archivo' => $archivo->Id_Archivo,
            'Id_Proveedor' => $idProveedor,
            'Id_Tipo_Documento' => $idTipo,
            'Fecha_Caducidad' => now()->addDays($diasParaVencer)->toDateString(),
            'Estado' => 'Cargado',
            'Estado_Calificacion' => 'Aprobado',
            'Activo' => 1,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function test_clasifica_cada_documento_en_su_tramo(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $esperado = [
            -5 => 'vencido',
            0 => 'critico',    // vence hoy
            3 => 'critico',
            12 => 'urgente',
            25 => 'proximo',
            90 => 'holgado',
        ];

        foreach (array_keys($esperado) as $dias) {
            $this->crearDocumento($proveedor->Id_Proveedor, $dias);
        }

        $respuesta = $this->getJson('/api/reportes/caducidad-documentos', $this->cabecerasComo($sistemas, $empresa));
        $respuesta->assertOk();

        $porDias = collect($respuesta->json('documentos'))->keyBy('dias_restantes');

        foreach ($esperado as $dias => $tramo) {
            $this->assertSame(
                $tramo,
                $porDias[$dias]['tramo'] ?? null,
                "Un documento que vence en {$dias} días debería caer en el tramo '{$tramo}'."
            );
        }
    }

    /** Los límites exactos de cada tramo, que es donde se cometen los errores. */
    public function test_los_cortes_caen_del_lado_correcto(): void
    {
        $this->assertSame('vencido', ReporteCaducidadService::tramoDe(-1));
        $this->assertSame('critico', ReporteCaducidadService::tramoDe(0));
        $this->assertSame('critico', ReporteCaducidadService::tramoDe(7));
        $this->assertSame('urgente', ReporteCaducidadService::tramoDe(8));
        $this->assertSame('urgente', ReporteCaducidadService::tramoDe(15));
        $this->assertSame('proximo', ReporteCaducidadService::tramoDe(16));
        $this->assertSame('proximo', ReporteCaducidadService::tramoDe(30));
        $this->assertSame('holgado', ReporteCaducidadService::tramoDe(31));
    }

    public function test_no_incluye_documentos_sin_fecha_de_caducidad(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $conFecha = $this->crearDocumento($proveedor->Id_Proveedor, 10);
        $sinFecha = $this->crearDocumento($proveedor->Id_Proveedor, 10);
        $sinFecha->forceFill(['Fecha_Caducidad' => null])->save();

        $ids = collect(
            $this->getJson('/api/reportes/caducidad-documentos', $this->cabecerasComo($sistemas, $empresa))
                ->json('documentos')
        )->pluck('id_documento_proveedor');

        $this->assertContains($conFecha->Id_Documento_Proveedor, $ids->all());
        $this->assertNotContains($sinFecha->Id_Documento_Proveedor, $ids->all());
    }

    public function test_no_muestra_documentos_de_otra_empresa(): void
    {
        $empresaA = $this->crearEmpresa();
        $empresaB = $this->crearEmpresa();
        [, $proveedorB] = $this->crearProveedorConUsuario($empresaB);
        $ajeno = $this->crearDocumento($proveedorB->Id_Proveedor, 10);

        $sistemasA = $this->crearUsuarioInterno($empresaA, 'Sistemas');

        $ids = collect(
            $this->getJson('/api/reportes/caducidad-documentos', $this->cabecerasComo($sistemasA, $empresaA))
                ->json('documentos')
        )->pluck('id_documento_proveedor');

        $this->assertNotContains($ajeno->Id_Documento_Proveedor, $ids->all());
    }

    // Un rol por test: Laravel resuelve el usuario autenticado UNA vez por
    // test y lo conserva entre llamadas, así que dos roles en el mismo test
    // viajarían los dos como el primero.
    public function test_admin_puede_verlo(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');

        $this->getJson('/api/reportes/caducidad-documentos', $this->cabecerasComo($admin, $empresa))
            ->assertOk();
    }

    public function test_calidad_puede_verlo(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->getJson('/api/reportes/caducidad-documentos', $this->cabecerasComo($calidad, $empresa))
            ->assertOk();
    }

    public function test_compras_no_puede_verlo(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->getJson('/api/reportes/caducidad-documentos', $this->cabecerasComo($compras, $empresa))
            ->assertForbidden();
    }

    public function test_devuelve_los_tramos_para_que_la_pantalla_no_los_repita(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $tramos = $this->getJson('/api/reportes/caducidad-documentos', $this->cabecerasComo($sistemas, $empresa))
            ->assertOk()
            ->json('tramos');

        $this->assertSame(
            ['vencido', 'critico', 'urgente', 'proximo', 'holgado'],
            array_column($tramos, 'clave'),
            'Los tramos deben venir del más urgente al menos urgente.'
        );
    }
}
