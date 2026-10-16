<?php

namespace Tests\Feature\Documentos;

use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Shared\ArchivoPdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un PDF con algo ANTES de "%PDF-" (16-oct-2026): llegó un registro
 * sanitario con un \r\n al principio que Acrobat abría bien y el portal
 * rechazaba como "no es PDF". Ver App\Shared\ArchivoPdf.
 */
class PdfConBytesAntesDelEncabezadoTest extends TestCase
{
    private const CUERPO_PDF = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('repositorio_proveedores');
    }

    private function tipoSimple(): TipoDocumento
    {
        return TipoDocumento::where('Activo', 1)
            ->where('Permite_Multiples', 0)
            ->where('Requiere_Fecha_Caducidad', 0)
            ->where('Codigo_Archivo', '<>', TipoDocumento::CODIGO_PLAN_CONTINGENCIA_NINO)
            ->firstOrFail();
    }

    private function subir(string $contenido)
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa);

        // Archivo real y no UploadedFile::fake(): el falso deduce el tipo por
        // la extensión, y acá lo que importa es cómo el servidor lee el
        // CONTENIDO, igual que con un archivo subido de verdad.
        $ruta = tempnam(sys_get_temp_dir(), 'test_pdf_');
        file_put_contents($ruta, $contenido);

        return $this->post(
            "/api/mi-documentos/{$this->tipoSimple()->Id_Tipo_Documento}",
            ['archivo' => new UploadedFile($ruta, 'registro.pdf', 'application/pdf', null, true)],
            $this->cabecerasComo($usuario, $empresa)
        );
    }

    public function test_un_pdf_con_un_salto_de_linea_antes_del_encabezado_se_acepta_y_se_guarda_limpio(): void
    {
        $respuesta = $this->subir("\r\n".self::CUERPO_PDF)->assertCreated();

        $documento = DocumentoProveedor::with('archivo')->findOrFail($respuesta->json('id_documento_proveedor'));
        $guardado = Storage::disk('repositorio_proveedores')->get($documento->archivo->Ruta_Almacenamiento);

        $this->assertStringStartsWith('%PDF-1.7', $guardado);
        $this->assertSame(self::CUERPO_PDF, $guardado);
        $this->assertSame(strlen(self::CUERPO_PDF), (int) $documento->archivo->Tamano_Bytes);
        $this->assertSame(hash('sha256', self::CUERPO_PDF), $documento->archivo->Hash_Archivo);
    }

    public function test_un_pdf_normal_se_guarda_tal_cual(): void
    {
        $respuesta = $this->subir(self::CUERPO_PDF)->assertCreated();

        $documento = DocumentoProveedor::with('archivo')->findOrFail($respuesta->json('id_documento_proveedor'));

        $this->assertSame(self::CUERPO_PDF, Storage::disk('repositorio_proveedores')->get($documento->archivo->Ruta_Almacenamiento));
    }

    /** Lo que no es PDF se sigue rechazando, y con un mensaje que dice qué hacer. */
    public function test_lo_que_no_es_pdf_se_rechaza_con_un_mensaje_claro(): void
    {
        $this->subir("PK\x03\x04 esto es un .docx renombrado")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['archivo' => 'no es un PDF válido']);
    }

    /** Igual que Acrobat: la firma tiene que aparecer en los primeros 1024 bytes. */
    public function test_la_firma_despues_de_los_primeros_1024_bytes_no_cuenta(): void
    {
        $this->subir(str_repeat(' ', ArchivoPdf::BYTES_TOLERADOS).self::CUERPO_PDF)->assertStatus(422);
    }
}
