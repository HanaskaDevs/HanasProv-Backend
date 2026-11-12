<?php

namespace Tests\Feature\Reportes;

use App\Modules\Reclamos\Services\ReclamoService;
use Tests\TestCase;

/**
 * Reporte "Historial de reclamos" (12-nov-2026): por proveedor, con la
 * conversación completa y descarga en PDF (de un reclamo o de todos).
 */
class HistorialReclamosTest extends TestCase
{
    /** Crea un reclamo con su primer mensaje. Devuelve [empresa, proveedor, reclamo]. */
    private function reclamoDePrueba(): array
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $reclamo = app(ReclamoService::class)->crear(
            $compras,
            $empresa->Id_Empresa,
            $proveedor->Id_Proveedor,
            'Producto en mal estado',
            'Calidad',
            'Alto',
            'Llegó un lote con empaques rotos.',
            [['rol_contacto' => 'Calidad', 'nombre_contacto' => 'Contacto', 'email' => 'contacto@proveedor-ejemplo.com']],
        );

        return [$empresa, $proveedor, $reclamo];
    }

    public function test_calidad_ve_el_historial_de_reclamos_por_proveedor(): void
    {
        [$empresa, $proveedor, $reclamo] = $this->reclamoDePrueba();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        $proveedores = $this->getJson('/api/reportes/historial-reclamos', $cabeceras)
            ->assertSuccessful()
            ->json();

        $fila = collect($proveedores)->firstWhere('id_proveedor', $proveedor->Id_Proveedor);
        $this->assertNotNull($fila, 'El proveedor con reclamos tiene que aparecer en el reporte.');
        $this->assertSame(1, $fila['total']);
        $this->assertSame(1, $fila['abiertos']);

        $historial = $this->getJson("/api/reportes/historial-reclamos/proveedor/{$proveedor->Id_Proveedor}", $cabeceras)
            ->assertSuccessful()
            ->json();

        $this->assertCount(1, $historial['reclamos']);
        $this->assertSame($reclamo->Id_Reclamo, $historial['reclamos'][0]['id_reclamo']);
        $this->assertSame(1, $historial['reclamos'][0]['total_mensajes']);
    }

    public function test_compras_y_el_proveedor_no_entran_al_reporte(): void
    {
        [$empresa, $proveedor] = $this->reclamoDePrueba();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [$usuarioProveedor] = $this->crearProveedorConUsuario($empresa);

        $this->getJson('/api/reportes/historial-reclamos', $this->cabecerasComo($compras, $empresa))
            ->assertForbidden();

        $this->getJson(
            "/api/reportes/historial-reclamos/proveedor/{$proveedor->Id_Proveedor}",
            $this->cabecerasComo($usuarioProveedor, $empresa)
        )->assertForbidden();
    }

    public function test_se_descarga_el_pdf_de_un_reclamo_y_el_del_proveedor(): void
    {
        [$empresa, $proveedor, $reclamo] = $this->reclamoDePrueba();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $unReclamo = $this->get("/api/reportes/historial-reclamos/reclamo/{$reclamo->Id_Reclamo}/pdf", $cabeceras)
            ->assertSuccessful();
        $this->assertSame('application/pdf', $unReclamo->headers->get('content-type'));

        $delProveedor = $this->get("/api/reportes/historial-reclamos/proveedor/{$proveedor->Id_Proveedor}/pdf", $cabeceras)
            ->assertSuccessful();
        $this->assertSame('application/pdf', $delProveedor->headers->get('content-type'));
        // download() de dompdf devuelve una respuesta normal (no streamed).
        $this->assertStringStartsWith('%PDF', $delProveedor->getContent());
    }

    public function test_un_reclamo_de_otra_empresa_no_se_descarga(): void
    {
        [, , $reclamo] = $this->reclamoDePrueba();
        $otraEmpresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($otraEmpresa, 'Sistemas');

        $this->get(
            "/api/reportes/historial-reclamos/reclamo/{$reclamo->Id_Reclamo}/pdf",
            $this->cabecerasComo($sistemas, $otraEmpresa)
        )->assertNotFound();
    }
}
