<?php

namespace Tests\Feature\Documentos;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Proveedores\Http\Requests\CalificarRequest;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Motivo de rechazo de un documento: hasta 250 caracteres (13-nov-2026).
 *
 * Antes la columna guardaba 50 y la validación aceptaba 1000: un motivo
 * largo hacía fallar el guardado y el rechazo no quedaba registrado.
 */
class MotivoRechazoDocumentoTest extends TestCase
{
    private function tipoComun(): TipoDocumento
    {
        $tipo = TipoDocumento::where('Requiere_Fecha_Caducidad', 0)
            ->where('Codigo_Archivo', '!=', TipoDocumento::CODIGO_PLAN_CONTINGENCIA_NINO)
            ->first();

        $this->assertNotNull($tipo, 'No hay un tipo de documento común en el catálogo.');

        return $tipo;
    }

    /** @return array{0: DocumentoProveedor, 1: Usuario, 2: Empresa} */
    private function documentoParaCalificar(): array
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $tipo = $this->tipoComun();

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

        $documento = DocumentoProveedor::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
            'Id_Archivo' => $archivo->Id_Archivo,
            'Activo' => 1,
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);

        return [$documento, $this->crearUsuarioInterno($empresa, 'Calidad'), $empresa];
    }

    private function url(DocumentoProveedor $documento): string
    {
        return "/api/proveedores/documentos-calificacion/{$documento->Id_Documento_Proveedor}";
    }

    public function test_el_tope_es_250(): void
    {
        $this->assertSame(250, CalificarRequest::MAX_MOTIVO_DOCUMENTO);
    }

    public function test_un_motivo_de_251_caracteres_se_rechaza_con_un_mensaje_claro_y_no_se_guarda(): void
    {
        [$documento, $calidad, $empresa] = $this->documentoParaCalificar();

        $this->postJson($this->url($documento), ['aprobado' => false, 'observacion' => str_repeat('a', 251)], $this->cabecerasComo($calidad, $empresa))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['observacion' => 'como máximo 250 caracteres']);

        $this->assertNull($documento->fresh()->Estado_Calificacion);
    }

    /** Necesita la migración 2026_11_13_110000 aplicada en la base del .env. */
    public function test_un_motivo_de_250_caracteres_se_guarda_completo(): void
    {
        $largo = (int) DB::selectOne(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS l FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_NAME = 'Documento_Proveedor' AND COLUMN_NAME = 'Comentario_Calificacion'"
        )->l;

        if ($largo < CalificarRequest::MAX_MOTIVO_DOCUMENTO) {
            $this->markTestSkipped("La columna todavía es de {$largo}: falta aplicar la migración 2026_11_13_110000.");
        }

        [$documento, $calidad, $empresa] = $this->documentoParaCalificar();
        $motivo = str_repeat('Falta la firma del representante legal. ', 7); // 280
        $motivo = mb_substr($motivo, 0, 250);

        $this->postJson($this->url($documento), ['aprobado' => false, 'observacion' => $motivo], $this->cabecerasComo($calidad, $empresa))
            ->assertOk()
            ->assertJson(['estado_calificacion' => 'Rechazado', 'comentario_calificacion' => $motivo]);

        $this->assertSame($motivo, $documento->fresh()->Comentario_Calificacion);
    }

    public function test_un_motivo_corto_sigue_funcionando(): void
    {
        [$documento, $calidad, $empresa] = $this->documentoParaCalificar();

        $this->postJson($this->url($documento), ['aprobado' => false, 'observacion' => 'Está ilegible.'], $this->cabecerasComo($calidad, $empresa))
            ->assertOk();

        $this->assertSame('Rechazado', $documento->fresh()->Estado_Calificacion);
    }
}
