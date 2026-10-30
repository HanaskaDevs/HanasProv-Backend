<?php

namespace Tests\Feature\Documentos;

use App\Modules\Documentos_Proveedor\Mail\DocumentacionIncompletaMail;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Services\DocumentoProveedorService;
use App\Modules\Documentos_Proveedor\Services\RecordatorioDocumentacionService;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Recordatorio de documentación incompleta (29-oct-2026): subió al menos un
 * documento, le faltan obligatorios y lleva 7 días sin subir nada nuevo ->
 * correo con la lista de lo que le falta; se repite cada 7 días.
 *
 * La base de pruebas tiene proveedores reales, así que cada prueba mira solo
 * si SU proveedor está o no en la lista.
 */
class RecordatorioDocumentacionTest extends TestCase
{
    private function servicio(): RecordatorioDocumentacionService
    {
        return app(RecordatorioDocumentacionService::class);
    }

    /** Sube UN documento obligatorio (el primero que le corresponde) con la fecha dada. */
    private function subirUnoHace(Proveedor $proveedor, int $dias): void
    {
        $tipo = app(DocumentoProveedorService::class)->tiposObligatoriosAplicables($proveedor)->first();
        $this->assertNotNull($tipo, 'La base de pruebas no tiene tipos de documento obligatorios.');

        $archivo = Archivo::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nombre_Original' => 'doc.pdf',
            'Ruta_Almacenamiento' => 'pruebas/'.uniqid().'.pdf',
            'Hash_Archivo' => hash('sha256', uniqid('', true)),
            'Tipo_Mime' => 'application/pdf',
            'Tamano_Bytes' => 1024,
            'Categoria_Archivo' => 'test',
            'Id_Usuario_Carga' => $proveedor->usuarios()->value('Usuario.Id_Usuario'),
            'Fecha_Carga' => now()->subDays($dias),
            'Activo' => 1,
        ]);

        DocumentoProveedor::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
            'Id_Archivo' => $archivo->Id_Archivo,
            'Estado' => 'Vigente',
            'Estado_Calificacion' => 'Pendiente',
            'Activo' => 1,
            'Fecha_Creacion' => now()->subDays($dias),
        ]);
    }

    private function proveedor(): Proveedor
    {
        [, $proveedor] = $this->crearProveedorConUsuario($this->crearEmpresa(), ['Ciudad' => 'Quito']);

        $this->assertGreaterThan(
            1,
            app(DocumentoProveedorService::class)->tiposObligatoriosAplicables($proveedor)->count(),
            'Hacen falta al menos 2 obligatorios para que subir uno deje la documentación incompleta.'
        );

        return $proveedor;
    }

    private function estaEnLaLista(Proveedor $proveedor): bool
    {
        return $this->servicio()->proveedoresParaRecordar()
            ->contains(fn (array $fila) => (int) $fila['proveedor']->Id_Proveedor === (int) $proveedor->Id_Proveedor);
    }

    public function test_subio_uno_hace_ocho_dias_y_le_faltan_otros_se_le_recuerda(): void
    {
        $proveedor = $this->proveedor();
        $this->subirUnoHace($proveedor, 8);

        $fila = $this->servicio()->proveedoresParaRecordar()
            ->first(fn (array $f) => (int) $f['proveedor']->Id_Proveedor === (int) $proveedor->Id_Proveedor);

        $this->assertNotNull($fila);
        $faltan = app(DocumentoProveedorService::class)->tiposObligatoriosAplicables($proveedor)->count() - 1;
        $this->assertCount($faltan, $fila['faltantes']);
    }

    public function test_si_subio_hace_menos_de_siete_dias_no_se_le_recuerda(): void
    {
        $proveedor = $this->proveedor();
        $this->subirUnoHace($proveedor, 3);

        $this->assertFalse($this->estaEnLaLista($proveedor));
    }

    public function test_si_no_subio_ninguno_no_se_le_recuerda(): void
    {
        $proveedor = $this->proveedor();

        $this->assertFalse($this->estaEnLaLista($proveedor));
    }

    public function test_el_comando_manda_el_correo_y_no_repite_hasta_la_semana_siguiente(): void
    {
        Mail::fake();
        $proveedor = $this->proveedor();
        $this->subirUnoHace($proveedor, 10);

        $this->artisan('documentos:recordar-incompletos')->assertSuccessful();

        Mail::assertQueued(DocumentacionIncompletaMail::class, fn ($mail) => $mail->hasTo($proveedor->Email)
            && count($mail->faltantes) > 0);
        $this->assertNotNull($proveedor->fresh()->Fecha_Aviso_Documentacion_Incompleta);

        // Mismo día: ya no.
        $this->assertFalse($this->estaEnLaLista($proveedor));

        // Una semana después del aviso, sin novedades: otra vez.
        $proveedor->forceFill(['Fecha_Aviso_Documentacion_Incompleta' => now()->subDays(7)])->save();
        $this->assertTrue($this->estaEnLaLista($proveedor));
    }

    public function test_simular_no_manda_ni_marca(): void
    {
        Mail::fake();
        $proveedor = $this->proveedor();
        $this->subirUnoHace($proveedor, 10);

        $this->artisan('documentos:recordar-incompletos --simular')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull($proveedor->fresh()->Fecha_Aviso_Documentacion_Incompleta);
    }
}
