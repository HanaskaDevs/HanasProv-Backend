<?php

namespace Tests\Feature\Documentos;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LUAE con fecha de caducidad (26-oct-2026):
 *  - el proveedor la indica al subirla;
 *  - las ya cargadas sin fecha: quien califica la escribe antes de aprobar;
 *  - las ya aprobadas: Admin o Calidad la completan aunque la calificación
 *    esté registrada;
 *  - solo se escribe en documentos que piden fecha.
 */
class LuaeCaducidadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('repositorio_proveedores');
    }

    private function tipoLuae(): TipoDocumento
    {
        $tipo = TipoDocumento::where('Codigo_Archivo', 'LUAE')->first();

        $this->assertNotNull($tipo, 'Falta el tipo LUAE en el catálogo.');
        $this->assertTrue($tipo->Requiere_Fecha_Caducidad, 'La LUAE todavía no pide fecha. Corré: php artisan migrate');

        return $tipo;
    }

    /** @return array{0: Usuario, 1: Proveedor, 2: Empresa} */
    private function proveedorDeQuito(): array
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Quito']);

        return [$usuario, $proveedor, $empresa];
    }

    /** Un documento ya cargado, como los de antes del cambio (sin fecha). */
    private function documentoCargado(Proveedor $proveedor, Usuario $usuario, TipoDocumento $tipo, array $extra = []): DocumentoProveedor
    {
        $archivo = Archivo::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nombre_Original' => 'documento.pdf',
            'Ruta_Almacenamiento' => 'pruebas/'.uniqid().'.pdf',
            'Hash_Archivo' => hash('sha256', uniqid('', true)),
            'Tipo_Mime' => 'application/pdf',
            'Tamano_Bytes' => 1024,
            'Categoria_Archivo' => $tipo->Carpeta_Slug,
            'Id_Usuario_Carga' => $usuario->Id_Usuario,
            'Fecha_Carga' => now(),
            'Activo' => 1,
        ]);

        return DocumentoProveedor::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
            'Id_Archivo' => $archivo->Id_Archivo,
            'Fecha_Caducidad' => null,
            'Activo' => 1,
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
            ...$extra,
        ]);
    }

    public function test_el_proveedor_tiene_que_indicar_la_fecha_al_subir_la_luae(): void
    {
        $tipo = $this->tipoLuae();
        [$usuario, , $empresa] = $this->proveedorDeQuito();

        $this->post(
            "/api/mi-documentos/{$tipo->Id_Tipo_Documento}",
            ['archivo' => UploadedFile::fake()->create('luae.pdf', 100, 'application/pdf')],
            $this->cabecerasComo($usuario, $empresa)
        )->assertStatus(422)->assertJsonValidationErrors('fecha_caducidad');

        $this->post(
            "/api/mi-documentos/{$tipo->Id_Tipo_Documento}",
            [
                'archivo' => UploadedFile::fake()->create('luae.pdf', 100, 'application/pdf'),
                'fecha_caducidad' => now()->addYear()->toDateString(),
            ],
            $this->cabecerasComo($usuario, $empresa)
        )->assertCreated();
    }

    public function test_quien_califica_no_aprueba_una_luae_sin_fecha_y_la_puede_completar(): void
    {
        $tipo = $this->tipoLuae();
        [$usuario, $proveedor, $empresa] = $this->proveedorDeQuito();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $luae = $this->documentoCargado($proveedor, $usuario, $tipo);
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        // La pantalla de calificación avisa que el tipo pide fecha.
        $fila = collect($this->getJson("/api/proveedores/{$proveedor->Id_Proveedor}/documentos-calificacion", $cabeceras)->assertOk()->json('documentos'))
            ->firstWhere('id_tipo_documento', $tipo->Id_Tipo_Documento);
        $this->assertTrue($fila['requiere_fecha_caducidad']);
        $this->assertNull($fila['documentos'][0]['fecha_caducidad']);

        // Sin fecha no se aprueba...
        $this->postJson("/api/proveedores/documentos-calificacion/{$luae->Id_Documento_Proveedor}", ['aprobado' => true], $cabeceras)
            ->assertStatus(422);
        $this->assertNull($luae->fresh()->Estado_Calificacion);

        // Se completa la fecha y ahí sí se aprueba.
        $fecha = now()->addMonths(8)->toDateString();
        $this->putJson("/api/proveedores/documentos-calificacion/{$luae->Id_Documento_Proveedor}/fecha-caducidad", ['fecha_caducidad' => $fecha], $cabeceras)
            ->assertOk()
            ->assertJson(['id_documento_proveedor' => $luae->Id_Documento_Proveedor, 'fecha_caducidad' => $fecha]);

        $this->postJson("/api/proveedores/documentos-calificacion/{$luae->Id_Documento_Proveedor}", ['aprobado' => true], $cabeceras)
            ->assertOk();

        $luae->refresh();
        $this->assertSame('Aprobado', $luae->Estado_Calificacion);
        $this->assertSame($fecha, $luae->Fecha_Caducidad->toDateString());
    }

    public function test_rechazar_una_luae_sin_fecha_si_se_puede(): void
    {
        $tipo = $this->tipoLuae();
        [$usuario, $proveedor, $empresa] = $this->proveedorDeQuito();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $luae = $this->documentoCargado($proveedor, $usuario, $tipo);

        $this->postJson(
            "/api/proveedores/documentos-calificacion/{$luae->Id_Documento_Proveedor}",
            ['aprobado' => false, 'observacion' => 'Está ilegible.'],
            $this->cabecerasComo($calidad, $empresa)
        )->assertOk();

        $this->assertSame('Rechazado', $luae->fresh()->Estado_Calificacion);
    }

    /** Las ya aprobadas: Admin o Calidad completan la fecha aunque la calificación esté registrada. */
    public function test_admin_completa_la_fecha_de_una_luae_ya_aprobada_con_calificacion_registrada(): void
    {
        $tipo = $this->tipoLuae();
        [$usuario, $proveedor, $empresa] = $this->proveedorDeQuito();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        $luae = $this->documentoCargado($proveedor, $usuario, $tipo, [
            'Estado_Calificacion' => 'Aprobado',
            'Calificado_Por' => $admin->Id_Usuario,
            'Fecha_Calificacion' => now()->subMonth(),
        ]);
        $proveedor->forceFill(['Fecha_Registro_Calificacion_Documentos' => now()->subMonth()])->save();

        $fecha = now()->addMonths(3)->toDateString();
        $this->putJson(
            "/api/proveedores/documentos-calificacion/{$luae->Id_Documento_Proveedor}/fecha-caducidad",
            ['fecha_caducidad' => $fecha],
            $this->cabecerasComo($admin, $empresa)
        )->assertOk();

        $luae->refresh();
        $this->assertSame($fecha, $luae->Fecha_Caducidad->toDateString());
        // El veredicto no cambia.
        $this->assertSame('Aprobado', $luae->Estado_Calificacion);
    }

    public function test_compras_y_el_proveedor_no_pueden_escribir_la_fecha(): void
    {
        $tipo = $this->tipoLuae();
        [$usuario, $proveedor, $empresa] = $this->proveedorDeQuito();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        $luae = $this->documentoCargado($proveedor, $usuario, $tipo);
        $url = "/api/proveedores/documentos-calificacion/{$luae->Id_Documento_Proveedor}/fecha-caducidad";
        $cuerpo = ['fecha_caducidad' => now()->addYear()->toDateString()];

        $this->putJson($url, $cuerpo, $this->cabecerasComo($compras, $empresa))->assertForbidden();
        $this->putJson($url, $cuerpo, $this->cabecerasComo($usuario, $empresa))->assertForbidden();

        $this->assertNull($luae->fresh()->Fecha_Caducidad);
    }

    public function test_no_se_escribe_fecha_en_un_documento_que_no_vence(): void
    {
        [$usuario, $proveedor, $empresa] = $this->proveedorDeQuito();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $sinVencimiento = TipoDocumento::where('Activo', 1)->where('Requiere_Fecha_Caducidad', 0)->whereNotNull('Codigo_Archivo')->first();
        $this->assertNotNull($sinVencimiento);
        $doc = $this->documentoCargado($proveedor, $usuario, $sinVencimiento);

        $this->putJson(
            "/api/proveedores/documentos-calificacion/{$doc->Id_Documento_Proveedor}/fecha-caducidad",
            ['fecha_caducidad' => now()->addYear()->toDateString()],
            $this->cabecerasComo($calidad, $empresa)
        )->assertStatus(422);

        $this->assertNull($doc->fresh()->Fecha_Caducidad);
    }

    public function test_la_fecha_tiene_que_ser_completa(): void
    {
        $tipo = $this->tipoLuae();
        [$usuario, $proveedor, $empresa] = $this->proveedorDeQuito();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $luae = $this->documentoCargado($proveedor, $usuario, $tipo);

        $this->putJson(
            "/api/proveedores/documentos-calificacion/{$luae->Id_Documento_Proveedor}/fecha-caducidad",
            ['fecha_caducidad' => '2027-05'],
            $this->cabecerasComo($calidad, $empresa)
        )->assertStatus(422);
    }
}
