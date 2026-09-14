<?php

namespace Tests\Feature\Proveedores;

use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Auditorias\Models\TipoAuditoria;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Services\DocumentoProveedorService;
use App\Modules\Pedidos\Models\DetallePedidoCompra;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\CalificacionGlobalService;
use App\Modules\Reclamos\Models\Reclamo;
use Tests\TestCase;

/**
 * Calificación global de desempeño, sobre 100 puntos:
 * fill rate 50 + auditoría de proveedor 15 + documentos 15 +
 * reclamos 10 + auditoría de recepción 10.
 *
 * Lo que más importa cubrir acá son las reglas que NO son obvias leyendo
 * los pesos: el promedio del fill rate se hace POR PEDIDO (no sobre el total
 * de cantidades), solo entran los pedidos cerrados, y lo que no se pudo
 * medir queda FUERA del cálculo (no puntúa ni aparece), con la nota
 * renormalizada sobre lo que sí se midió.
 */
class CalificacionGlobalTest extends TestCase
{
    private function servicio(): CalificacionGlobalService
    {
        return app(CalificacionGlobalService::class);
    }

    private function componente(array $resultado, string $clave): array
    {
        foreach ($resultado['componentes'] as $componente) {
            if ($componente['clave'] === $clave) {
                return $componente;
            }
        }

        $this->fail("El componente '{$clave}' no está entre los evaluados.");
    }

    /** Claves de los componentes que quedaron fuera del cálculo por falta de datos. */
    private function clavesSinDatos(array $resultado): array
    {
        return array_column($resultado['componentes_sin_datos'], 'clave');
    }

    /** true si ese componente entró al cálculo. */
    private function fueEvaluado(array $resultado, string $clave): bool
    {
        return in_array($clave, array_column($resultado['componentes'], 'clave'), true);
    }

    /**
     * Un pedido con las líneas indicadas como [cantidad, recibida].
     */
    private function pedidoCon(Proveedor $proveedor, string $estado, array $lineas): PedidoCompra
    {
        $pedido = PedidoCompra::create([
            'Id_Empresa' => $proveedor->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nro_Pedido' => 'TEST-'.random_int(100000, 999999),
            'Fecha_Registro_BC' => now()->subDays(10)->toDateString(),
            'Fecha_Sincronizacion' => now(),
            'Activo' => 1,
            'Estado' => $estado,
        ]);

        foreach ($lineas as $i => [$cantidad, $recibida]) {
            DetallePedidoCompra::create([
                'Id_Pedido_Compra' => $pedido->Id_Pedido_Compra,
                'Nro_Linea' => ($i + 1) * 10000,
                'Codigo_Producto' => 'COD-'.$i,
                'Descripcion' => 'Linea de prueba '.$i,
                'Cantidad' => $cantidad,
                'Cantidad_Recibida' => $recibida,
            ]);
        }

        return $pedido;
    }

    /** Deja al proveedor con TODOS sus documentos obligatorios aprobados y vigentes. */
    private function documentacionCompleta(Proveedor $proveedor, int $idUsuarioCarga): void
    {
        $tipos = app(DocumentoProveedorService::class)->tiposObligatoriosAplicables($proveedor);

        foreach ($tipos as $tipo) {
            $archivo = Archivo::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Nombre_Original' => 'ok.pdf',
                'Ruta_Almacenamiento' => 'tests/ok.pdf',
                'Hash_Archivo' => hash('sha256', 'ok'.$tipo->Id_Tipo_Documento),
                'Tipo_Mime' => 'application/pdf',
                'Tamano_Bytes' => 1024,
                'Categoria_Archivo' => 'test',
                'Id_Usuario_Carga' => $idUsuarioCarga,
                'Fecha_Carga' => now(),
                'Activo' => 1,
            ]);

            DocumentoProveedor::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
                'Id_Archivo' => $archivo->Id_Archivo,
                'Estado' => 'Vigente',
                'Estado_Calificacion' => 'Aprobado',
                'Activo' => 1,
                'Fecha_Creacion' => now(),
            ]);
        }
    }

    public function test_los_pesos_de_las_secciones_suman_cien(): void
    {
        $this->assertSame(100,
            CalificacionGlobalService::PESO_FILL_RATE
            + CalificacionGlobalService::PESO_AUDITORIA_PROVEEDOR
            + CalificacionGlobalService::PESO_DOCUMENTOS
            + CalificacionGlobalService::PESO_RECLAMOS
            + CalificacionGlobalService::PESO_AUDITORIA_RECEPCION
        );
    }

    public function test_el_fill_rate_promedia_por_pedido_y_no_por_cantidad_total(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        // Pedido chico entregado completo, y pedido grande entregado a la
        // mitad. Promediando POR PEDIDO da (100 + 50) / 2 = 75%.
        // Si se promediara por cantidad total daría
        // (1 + 500) / (1 + 1000) = 50.05%, que es un resultado muy distinto:
        // este test es justamente el que fija cuál de las dos rige.
        $this->pedidoCon($proveedor, 'Cerrado', [[1, 1]]);
        $this->pedidoCon($proveedor, 'Cerrado', [[1000, 500]]);

        $fillRate = $this->componente($this->servicio()->calcular($proveedor->fresh()), 'fill_rate');

        $this->assertSame(75.0, $fillRate['porcentaje']);
        $this->assertSame(37.5, $fillRate['puntaje']);
    }

    public function test_los_pedidos_abiertos_no_entran_al_fill_rate(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->pedidoCon($proveedor, 'Cerrado', [[10, 10]]);
        // Este todavía no se entregó. Si contara, el promedio bajaría a 50%
        // y el proveedor quedaría castigado por un pedido en curso.
        $this->pedidoCon($proveedor, 'Abierto', [[10, 0]]);

        $fillRate = $this->componente($this->servicio()->calcular($proveedor->fresh()), 'fill_rate');

        $this->assertSame(100.0, $fillRate['porcentaje']);
        $this->assertStringContainsString('1 pedido', $fillRate['detalle']);
    }

    public function test_una_sobre_entrega_no_tapa_el_faltante_de_otra_linea(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        // Pidieron 10 y 10. Entregaron 20 de la primera (el doble) y 0 de la
        // segunda. Sin tope por línea el pedido daría 100% (20 de 20 en
        // total) cuando en realidad una línea nunca llegó -> con tope, la
        // primera cuenta 10 y el pedido da 50%.
        $this->pedidoCon($proveedor, 'Cerrado', [[10, 20], [10, 0]]);

        $fillRate = $this->componente($this->servicio()->calcular($proveedor->fresh()), 'fill_rate');

        $this->assertSame(50.0, $fillRate['porcentaje']);
    }

    /**
     * Lo que NO se midió no puntúa ni aparece: su peso se saca del
     * denominador. Antes se le regalaba el puntaje completo, lo que hacía
     * que un proveedor sin auditar sacara más nota que uno auditado al 80%.
     */
    public function test_lo_que_no_se_midio_no_aparece_ni_puntua(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Quito']);

        $resultado = $this->servicio()->calcular($proveedor);

        // Las dos auditorías y el fill rate no tienen con qué medirse.
        $this->assertFalse($this->fueEvaluado($resultado, 'auditoria_proveedor'));
        $this->assertFalse($this->fueEvaluado($resultado, 'auditoria_recepcion'));
        $this->assertFalse($this->fueEvaluado($resultado, 'fill_rate'));

        $sinDatos = $this->clavesSinDatos($resultado);
        $this->assertContains('auditoria_proveedor', $sinDatos);
        $this->assertContains('auditoria_recepcion', $sinDatos);
        $this->assertContains('fill_rate', $sinDatos);

        // Solo documentación (15) y reclamos (10) entran al denominador.
        $this->assertSame(25, $resultado['peso_evaluado']);
        $this->assertTrue($resultado['evaluable']);
    }

    /**
     * Un proveedor al que solo se le mide la documentación, y la tiene al
     * día, saca 100: no se lo castiga por auditorías que la empresa no hizo.
     */
    public function test_con_lo_poco_evaluado_en_orden_la_nota_es_cien(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Quito']);
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');

        $this->documentacionCompleta($proveedor, $admin->Id_Usuario);

        $resultado = $this->servicio()->calcular($proveedor->fresh());

        $this->assertSame(100.0, $resultado['puntaje_total']);
        $this->assertSame(25, $resultado['peso_evaluado']);
    }

    /**
     * La nota es lo obtenido sobre lo evaluado, llevado a 100, y las filas
     * conservan su peso original (documentación vale 15 siempre, no un peso
     * escalado que nadie entiende al leerlo).
     */
    public function test_la_nota_es_lo_obtenido_sobre_lo_evaluado(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Quito']);
        $interno = $this->crearUsuarioInterno($empresa, 'Calidad');

        // Documentación en 0 de 15, reclamos en 10 de 10 -> 10 sobre 25 = 40.
        Reclamo::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Asunto' => 'Da igual el asunto',
            'Estado' => 'Abierto',
            'Creado_Por' => $interno->Id_Usuario,
            'Activo' => 1,
            'Fecha_Creacion' => now()->subMonths(20),
        ]);

        $resultado = $this->servicio()->calcular($proveedor->fresh());

        $documentos = $this->componente($resultado, 'documentos');
        $this->assertSame(15, $documentos['peso'], 'La fila conserva su peso original.');
        $this->assertSame(0.0, $documentos['puntaje']);

        $this->assertSame(10.0, $resultado['puntaje_obtenido']);
        $this->assertSame(25, $resultado['peso_evaluado']);
        $this->assertSame(40.0, $resultado['puntaje_total']);
    }

    public function test_manda_la_ultima_auditoria_finalizada_y_no_la_de_borrador(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $auditor = $this->crearUsuarioInterno($empresa, 'Calidad');
        $tipo = TipoAuditoria::firstOrFail();

        $base = [
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Tipo_Auditoria' => $tipo->Id_Tipo_Auditoria,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Usuario_Auditor' => $auditor->Id_Usuario,
            'Fecha_Creacion' => now(),
        ];

        // Vieja finalizada al 40%, nueva finalizada al 80%, y un borrador al
        // 10% que no debe influir en nada.
        Auditoria::create([...$base, 'Fecha_Auditoria' => now()->subMonths(6)->toDateString(), 'Estado' => 'Finalizada', 'Porcentaje_Cumplimiento' => 40]);
        Auditoria::create([...$base, 'Fecha_Auditoria' => now()->subDays(5)->toDateString(), 'Estado' => 'Finalizada', 'Porcentaje_Cumplimiento' => 80]);
        Auditoria::create([...$base, 'Fecha_Auditoria' => now()->toDateString(), 'Estado' => 'Borrador', 'Porcentaje_Cumplimiento' => 10]);

        $auditoria = $this->componente($this->servicio()->calcular($proveedor), 'auditoria_proveedor');

        $this->assertSame(80.0, $auditoria['porcentaje']);
        $this->assertSame(12.0, $auditoria['puntaje']); // 80% de 15
    }

    public function test_la_auditoria_de_recepcion_usa_el_porcentaje_guardado(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $auditor = $this->crearUsuarioInterno($empresa, 'Calidad');

        CalificacionRecepcion::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Usuario_Auditor' => $auditor->Id_Usuario,
            'Fecha_Recepcion' => now()->subDays(3)->toDateString(),
            'Estado' => 'Finalizada',
            'Puntaje_Total_Posible' => 200,
            'Puntaje_Obtenido' => 150,
            'Porcentaje_Obtenido' => 75,
            'Fecha_Creacion' => now(),
        ]);

        $recepcion = $this->componente($this->servicio()->calcular($proveedor), 'auditoria_recepcion');

        $this->assertSame(75.0, $recepcion['porcentaje']);
        $this->assertSame(7.5, $recepcion['puntaje']); // 75% de 10
    }

    public function test_los_documentos_son_todo_o_nada(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Quito']);
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');

        // Sin ningún documento: 0 puntos, no un puntaje parcial.
        $sinNada = $this->componente($this->servicio()->calcular($proveedor), 'documentos');
        $this->assertSame(0.0, $sinNada['puntaje']);

        $this->documentacionCompleta($proveedor, $admin->Id_Usuario);

        $completo = $this->componente($this->servicio()->calcular($proveedor->fresh()), 'documentos');
        $this->assertSame(15.0, $completo['puntaje']);
        $this->assertSame(100.0, $completo['porcentaje']);
    }

    public function test_un_documento_vencido_deja_la_seccion_de_documentos_en_cero(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Quito']);
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');

        $this->documentacionCompleta($proveedor, $admin->Id_Usuario);
        $this->assertSame(15.0, $this->componente($this->servicio()->calcular($proveedor->fresh()), 'documentos')['puntaje']);

        // Se vence uno solo de los obligatorios: aprobado sigue estando,
        // pero ya no sirve -> la sección entera cae a 0.
        DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->orderBy('Id_Documento_Proveedor')
            ->limit(1)
            ->update(['Fecha_Caducidad' => now()->subDay()->format('Y-m-d')]);

        $vencido = $this->componente($this->servicio()->calcular($proveedor->fresh()), 'documentos');

        $this->assertSame(0.0, $vencido['puntaje']);
        $this->assertStringContainsString('vencido', $vencido['detalle']);
    }

    public function test_cada_reclamo_descuenta_un_punto_y_la_seccion_no_baja_de_cero(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $interno = $this->crearUsuarioInterno($empresa, 'Calidad');

        $crearReclamos = function (int $cuantos) use ($empresa, $proveedor, $interno) {
            for ($i = 0; $i < $cuantos; $i++) {
                Reclamo::create([
                    'Id_Empresa' => $empresa->Id_Empresa,
                    'Id_Proveedor' => $proveedor->Id_Proveedor,
                    'Asunto' => 'Reclamo de prueba '.$i,
                    'Estado' => 'Abierto',
                    'Creado_Por' => $interno->Id_Usuario,
                    'Activo' => 1,
                    'Fecha_Creacion' => now(),
                ]);
            }
        };

        $this->assertSame(10.0, $this->componente($this->servicio()->calcular($proveedor), 'reclamos')['puntaje']);

        $crearReclamos(3);
        $this->assertSame(7.0, $this->componente($this->servicio()->calcular($proveedor), 'reclamos')['puntaje']);

        // 12 reclamos en total: la sección toca fondo en 0 y NO resta de
        // las otras secciones.
        // 12 reclamos: la sección toca fondo en 0 y NO resta de las otras.
        $crearReclamos(9);
        $conMuchos = $this->servicio()->calcular($proveedor);
        $this->assertSame(0.0, $this->componente($conMuchos, 'reclamos')['puntaje']);
        $this->assertGreaterThanOrEqual(0, $conMuchos['puntaje_total']);
    }

    public function test_un_reclamo_de_hace_mas_de_un_anio_ya_no_penaliza(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $interno = $this->crearUsuarioInterno($empresa, 'Calidad');

        Reclamo::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Asunto' => 'Reclamo viejo',
            'Estado' => 'Cerrado',
            'Creado_Por' => $interno->Id_Usuario,
            'Activo' => 1,
            'Fecha_Creacion' => now()->subMonths(14),
        ]);

        $reclamos = $this->componente($this->servicio()->calcular($proveedor), 'reclamos');

        $this->assertSame(10.0, $reclamos['puntaje']);
        $this->assertStringContainsString('Sin reclamos', $reclamos['detalle']);
    }

    public function test_un_proveedor_no_ve_la_calificacion_de_otro(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioA, $proveedorA] = $this->crearProveedorConUsuario($empresa);
        [, $proveedorB] = $this->crearProveedorConUsuario($empresa);

        // Su propia nota: sí.
        $this->getJson('/api/mi-calificacion-global', $this->cabecerasComo($usuarioA, $empresa))
            ->assertOk();

        // La del vecino por el endpoint interno: no.
        $this->getJson("/api/proveedores/{$proveedorB->Id_Proveedor}/calificacion-global", $this->cabecerasComo($usuarioA, $empresa))
            ->assertForbidden();

        $this->assertNotSame($proveedorA->Id_Proveedor, $proveedorB->Id_Proveedor);
    }

    public function test_no_se_puede_leer_la_calificacion_de_un_proveedor_de_otra_empresa(): void
    {
        $empresaPropia = $this->crearEmpresa();
        $empresaAjena = $this->crearEmpresa();

        $admin = $this->crearUsuarioInterno($empresaPropia, 'Admin');
        [, $proveedorAjeno] = $this->crearProveedorConUsuario($empresaAjena);

        $this->getJson("/api/proveedores/{$proveedorAjeno->Id_Proveedor}/calificacion-global", $this->cabecerasComo($admin, $empresaPropia))
            ->assertNotFound();
    }

    public function test_el_guardia_no_puede_ver_calificaciones(): void
    {
        $empresa = $this->crearEmpresa();
        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->getJson("/api/proveedores/{$proveedor->Id_Proveedor}/calificacion-global", $this->cabecerasComo($guardia, $empresa))
            ->assertForbidden();
    }

    public function test_el_endpoint_interno_devuelve_el_desglose_completo(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->getJson("/api/proveedores/{$proveedor->Id_Proveedor}/calificacion-global", $this->cabecerasComo($calidad, $empresa))
            ->assertOk()
            ->assertJsonStructure([
                'id_proveedor',
                'razon_social',
                'puntaje_total',
                'puntaje_obtenido',
                'peso_evaluado',
                'evaluable',
                'componentes' => [['clave', 'etiqueta', 'peso', 'porcentaje', 'puntaje', 'detalle']],
                'componentes_sin_datos' => [['clave', 'etiqueta', 'peso_original', 'motivo']],
            ]);
    }
}
