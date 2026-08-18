<?php

namespace Tests\Feature\Proveedores;

use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Notifications\ProveedorAprobadoNotification;
use App\Modules\Proveedores\Services\CalificacionProveedorService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * El paso de Aspirante -> Aprobado es automático y depende de que se
 * cumplan LAS TRES condiciones a la vez: ficha aprobada, documentación
 * aprobada (todo lo cargado calificado y sin rechazos) y AL MENOS UN
 * producto aprobado. Se dispara como efecto secundario de calificar
 * cualquiera de las tres cosas, así que lo que importa es que ninguna
 * combinación parcial alcance para aprobar a alguien.
 */
class AprobacionAutomaticaTest extends TestCase
{
    private function documentoDe(Proveedor $proveedor, int $idUsuarioCarga): DocumentoProveedor
    {
        $tipo = TipoDocumento::where('Activo', 1)->firstOrFail();

        $archivo = Archivo::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nombre_Original' => 'test.pdf',
            'Ruta_Almacenamiento' => 'tests/test.pdf',
            'Hash_Archivo' => hash('sha256', 'test'),
            'Tipo_Mime' => 'application/pdf',
            'Tamano_Bytes' => 1024,
            'Categoria_Archivo' => 'test',
            'Id_Usuario_Carga' => $idUsuarioCarga,
            'Fecha_Carga' => now(),
            'Activo' => 1,
        ]);

        return DocumentoProveedor::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
            'Id_Archivo' => $archivo->Id_Archivo,
            'Estado' => 'Vigente',
            'Activo' => 1,
            'Fecha_Creacion' => now(),
        ]);
    }

    private function productoDe(Proveedor $proveedor): Producto
    {
        return Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => UnidadPresentacion::firstOrFail()->Id_Unidad_Presentacion,
            'Nombre_Producto' => 'PRODUCTO DE PRUEBA',
            'Activo' => 1,
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Pendiente',
            'Fecha_Creacion' => now(),
        ]);
    }

    public function test_se_aprueba_solo_cuando_se_cumplen_las_tres_condiciones(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $documento = $this->documentoDe($proveedor, $admin->Id_Usuario);
        $producto = $this->productoDe($proveedor);

        $servicio = app(CalificacionProveedorService::class);

        // 1. Ficha aprobada -> todavía falta documentación y producto.
        $servicio->calificarFichaGeneral($admin, $empresa->Id_Empresa, $proveedor->Id_Proveedor, aprobado: true);
        $this->assertSame(
            EstadoProveedor::ASPIRANTE,
            (int) $proveedor->fresh()->Id_Estado_Proveedor,
            'Con solo la ficha aprobada no debería aprobarse.'
        );

        // 2. Documento aprobado -> todavía falta el producto.
        $servicio->calificarDocumento($admin, $empresa->Id_Empresa, $documento->Id_Documento_Proveedor, aprobado: true, observacion: null);
        $this->assertSame(
            EstadoProveedor::ASPIRANTE,
            (int) $proveedor->fresh()->Id_Estado_Proveedor,
            'Sin ningún producto aprobado no debería aprobarse.'
        );

        // 3. Producto aprobado -> ahora sí se cumplen las tres.
        $servicio->calificarProducto($admin, $empresa->Id_Empresa, $producto->Id_Producto, aprobado: true, observacion: null);

        $final = $proveedor->fresh();
        $this->assertSame(EstadoProveedor::APROBADO, (int) $final->Id_Estado_Proveedor);
        $this->assertNotNull($final->Fecha_Aprobacion, 'Al aprobar debe quedar registrada la fecha.');

        Notification::assertSentOnDemand(ProveedorAprobadoNotification::class);
    }

    public function test_un_documento_rechazado_impide_la_aprobacion(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $documento = $this->documentoDe($proveedor, $admin->Id_Usuario);
        $producto = $this->productoDe($proveedor);

        $servicio = app(CalificacionProveedorService::class);
        $servicio->calificarFichaGeneral($admin, $empresa->Id_Empresa, $proveedor->Id_Proveedor, aprobado: true);
        $servicio->calificarProducto($admin, $empresa->Id_Empresa, $producto->Id_Producto, aprobado: true, observacion: null);
        $servicio->calificarDocumento(
            $admin,
            $empresa->Id_Empresa,
            $documento->Id_Documento_Proveedor,
            aprobado: false,
            observacion: 'El documento está vencido.'
        );

        $final = $proveedor->fresh();
        $this->assertSame(EstadoProveedor::ASPIRANTE, (int) $final->Id_Estado_Proveedor);
        // Rechazar algo tiene que abrirle la corrección al proveedor.
        $this->assertTrue((bool) $final->Correcciones_Pendientes);
        Notification::assertNothingSent();
    }

    /**
     * Rechazar campos de la ficha deja el estado general en 'Rechazado', y
     * eso por sí solo ya bloquea la aprobación.
     */
    public function test_una_ficha_con_campos_rechazados_no_aprueba(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $documento = $this->documentoDe($proveedor, $admin->Id_Usuario);
        $producto = $this->productoDe($proveedor);

        $servicio = app(CalificacionProveedorService::class);
        $servicio->calificarFichaGeneral(
            $admin,
            $empresa->Id_Empresa,
            $proveedor->Id_Proveedor,
            aprobado: false,
            camposRechazados: [['campo' => 'ruc', 'observacion' => 'El RUC no coincide con el SRI.']]
        );
        $servicio->calificarDocumento($admin, $empresa->Id_Empresa, $documento->Id_Documento_Proveedor, aprobado: true, observacion: null);
        $servicio->calificarProducto($admin, $empresa->Id_Empresa, $producto->Id_Producto, aprobado: true, observacion: null);

        $final = $proveedor->fresh(['calificacionesCampos']);
        $this->assertSame('Rechazado', $final->estadoGeneralCalificacionFicha());
        $this->assertSame(EstadoProveedor::ASPIRANTE, (int) $final->Id_Estado_Proveedor);
    }

    /** Un rol sin permiso no puede calificar, aunque tenga acceso a la empresa. */
    public function test_compras_no_puede_calificar(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

        app(CalificacionProveedorService::class)
            ->calificarFichaGeneral($compras, $empresa->Id_Empresa, $proveedor->Id_Proveedor, aprobado: true);
    }
}
