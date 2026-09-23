<?php

namespace Tests\Feature\Documentos;

use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Proveedores\Models\EstadoProveedor;
use Tests\TestCase;

/**
 * Tres correcciones del 23-sep-2026:
 *
 *  1. La fecha de caducidad tiene que traer al menos un mes de vigencia.
 *  2. "Vencido" y "próximo a vencer" son cosas distintas: la bandera vieja
 *     daba verdadero para las dos, y la tarjeta decía "Próximo a vencer"
 *     sobre un documento caducado hacía 241 días.
 *  3. Un proveedor SUSPENDIDO que regulariza vuelve solo a Aprobado.
 */
class VigenciaYReactivacionTest extends TestCase
{
    private function documento($proveedor, string $fechaCaducidad, int $idUsuario): DocumentoProveedor
    {
        $archivo = Archivo::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nombre_Original' => 'prueba.pdf',
            'Ruta_Almacenamiento' => 'pruebas/'.uniqid().'.pdf',
            'Hash_Archivo' => hash('sha256', uniqid('', true)),
            'Tipo_Mime' => 'application/pdf',
            'Tamano_Bytes' => 1024,
            'Categoria_Archivo' => 'prueba',
            'Id_Usuario_Carga' => $idUsuario,
            'Fecha_Carga' => now(),
            'Activo' => 1,
        ]);

        return DocumentoProveedor::create([
            'Id_Archivo' => $archivo->Id_Archivo,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Tipo_Documento' => TipoDocumento::where('Activo', 1)->value('Id_Tipo_Documento'),
            'Fecha_Caducidad' => $fechaCaducidad,
            'Estado' => 'Cargado',
            'Estado_Calificacion' => 'Aprobado',
            'Activo' => 1,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function test_un_documento_vencido_no_se_muestra_como_proximo_a_vencer(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor, $proveedor] = $this->crearProveedorConUsuario($empresa);

        // El caso real de la captura: venció hace meses.
        $this->documento($proveedor, now()->subDays(241)->toDateString(), $usuarioProveedor->Id_Usuario);

        $respuesta = $this->getJson('/api/mi-documentos', $this->cabecerasComo($usuarioProveedor, $empresa));
        $respuesta->assertOk();

        $doc = collect($respuesta->json('documentos'))
            ->flatMap(fn ($tipo) => $tipo['documentos'])
            ->firstWhere('fecha_caducidad', now()->subDays(241)->toDateString());

        $this->assertNotNull($doc, 'El documento debería aparecer en el checklist.');
        $this->assertTrue($doc['vencido'], 'Venció hace 241 días.');
        $this->assertFalse($doc['proximo_a_vencer'], 'Ya venció: no está "próximo a vencer".');
        $this->assertLessThan(0, $doc['dias_para_vencer']);
    }

    public function test_uno_que_vence_pronto_si_se_marca_como_proximo(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->documento($proveedor, now()->addDays(10)->toDateString(), $usuarioProveedor->Id_Usuario);

        $doc = collect($this->getJson('/api/mi-documentos', $this->cabecerasComo($usuarioProveedor, $empresa))->json('documentos'))
            ->flatMap(fn ($tipo) => $tipo['documentos'])
            ->firstWhere('fecha_caducidad', now()->addDays(10)->toDateString());

        $this->assertTrue($doc['proximo_a_vencer']);
        $this->assertFalse($doc['vencido']);
    }

    public function test_no_se_puede_cargar_un_documento_con_menos_de_un_mes_de_vigencia(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor] = $this->crearProveedorConUsuario($empresa);
        $idTipo = TipoDocumento::where('Activo', 1)->value('Id_Tipo_Documento');
        $cabeceras = $this->cabecerasComo($usuarioProveedor, $empresa);

        $archivo = \Illuminate\Http\UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        foreach ([
            'una fecha ya vencida' => now()->subMonth()->toDateString(),
            'hoy mismo' => now()->toDateString(),
            'dentro de 10 días' => now()->addDays(10)->toDateString(),
        ] as $caso => $fecha) {
            $this->post(
                "/api/mi-documentos/{$idTipo}",
                ['archivo' => $archivo, 'fecha_caducidad' => $fecha],
                $cabeceras
            )->assertStatus(422, "Debería rechazar {$caso}.");
        }
    }

    public function test_un_proveedor_suspendido_que_regulariza_vuelve_a_aprobado(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');

        // Suspendido por documentación vencida, que es como llegan.
        $proveedor->forceFill(['Id_Estado_Proveedor' => EstadoProveedor::SUSPENDIDO])->save();

        $vencido = $this->documento($proveedor, now()->subDays(60)->toDateString(), $usuarioProveedor->Id_Usuario);

        $servicio = app(\App\Modules\Proveedores\Services\CalificacionProveedorService::class);

        // Las otras dos condiciones tienen que estar cumplidas: la
        // reactivación exige lo mismo que la aprobación inicial (ficha,
        // documentación y al menos un producto aprobados). Acá interesa
        // aislar el efecto del DOCUMENTO VENCIDO, así que el resto se deja
        // ya en verde.
        $servicio->calificarFichaGeneral($admin, $empresa->Id_Empresa, $proveedor->Id_Proveedor, aprobado: true);

        \App\Modules\Ficha_Productos\Models\Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => \App\Modules\Ficha_Productos\Models\UnidadPresentacion::firstOrFail()->Id_Unidad_Presentacion,
            'Nombre_Producto' => 'PRODUCTO YA APROBADO',
            'Activo' => 1,
            'Bloqueado' => 1,
            'Estado_Calificacion' => 'Aprobado',
            'Fecha_Creacion' => now(),
        ]);

        // Con el documento TODAVÍA vencido no se reactiva, aunque esté
        // aprobado: si no, el comando de la mañana lo volvería a suspender
        // y quedaría un ida y vuelta diario con su correo cada vez.
        $servicio->calificarDocumento($admin, $empresa->Id_Empresa, $vencido->Id_Documento_Proveedor, aprobado: true, observacion: null);
        $this->assertSame(
            EstadoProveedor::SUSPENDIDO,
            (int) $proveedor->fresh()->Id_Estado_Proveedor,
            'Con un documento vencido encima no corresponde reactivarlo.'
        );

        // Lo reemplaza por uno vigente -> ahora sí.
        $vencido->forceFill(['Fecha_Caducidad' => now()->addYear()->toDateString()])->save();
        $servicio->calificarDocumento($admin, $empresa->Id_Empresa, $vencido->Id_Documento_Proveedor, aprobado: true, observacion: null);

        $this->assertSame(
            EstadoProveedor::APROBADO,
            (int) $proveedor->fresh()->Id_Estado_Proveedor,
            'Regularizado, tiene que volver a Aprobado solo.'
        );
    }
}
