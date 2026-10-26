<?php

namespace Tests\Feature\Recepciones;

use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Auditorias\Models\RecepcionParametro;
use App\Modules\Auditorias\Services\AgendaRecepcionService;
use App\Modules\Auditorias\Services\CalificacionRecepcionService;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Documentos_Proveedor\Services\DocumentoProveedorService;
use Carbon\Carbon;
use Database\Seeders\RecepcionParametroSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Calificación de Recepciones (formulario FGH04.15.05-1).
 */
class CalificacionRecepcionTest extends TestCase
{
    private function servicio(): CalificacionRecepcionService
    {
        return app(CalificacionRecepcionService::class);
    }

    /**
     * El total impreso en el formulario oficial es 200. Si alguien toca un
     * puntaje del seeder sin querer, esto lo caza antes que un auditor.
     */
    public function test_los_parametros_del_formulario_suman_doscientos(): void
    {
        $parametros = RecepcionParametro::where('Activo', 1)->get();

        $this->assertCount(13, $parametros, 'El formulario tiene 13 parámetros.');
        $this->assertSame(
            (float) RecepcionParametroSeeder::PUNTAJE_TOTAL_ESPERADO,
            (float) $parametros->sum('Puntaje')
        );
    }

    public function test_el_puntaje_y_el_porcentaje_salen_de_lo_respondido(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);

        $servicio = $this->servicio();
        $calificacion = $servicio->obtenerOCrearBorrador($calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor);
        $id = $calificacion->Id_Calificacion_Recepcion;

        // Todo afirmativo menos el de 10 puntos -> 190 de 200 = 95%.
        foreach (RecepcionParametro::where('Activo', 1)->get() as $parametro) {
            $cumple = (float) $parametro->Puntaje !== 10.0;
            $servicio->guardarRespuesta($calidad, $empresa->Id_Empresa, $id, $parametro->Id_Recepcion_Parametro, $cumple, null);
        }

        $servicio->finalizar($calidad, $empresa->Id_Empresa, $id);
        $detalle = $servicio->obtenerDetalle($calidad, $empresa->Id_Empresa, $id);

        // Hay dos parámetros de 10 puntos (empaques y frecuencia de entrega).
        $this->assertSame(180.0, $detalle['resumen']['puntaje_obtenido']);
        $this->assertSame(200.0, $detalle['resumen']['puntaje_total_posible']);
        $this->assertSame(90.0, $detalle['resumen']['porcentaje_obtenido']);
        $this->assertTrue($detalle['finalizada']);
    }

    public function test_no_se_puede_finalizar_con_parametros_sin_responder(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);

        $servicio = $this->servicio();
        $calificacion = $servicio->obtenerOCrearBorrador($calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor);

        $primero = RecepcionParametro::where('Activo', 1)->orderBy('Orden')->first();
        $servicio->guardarRespuesta($calidad, $empresa->Id_Empresa, $calificacion->Id_Calificacion_Recepcion, $primero->Id_Recepcion_Parametro, true, null);

        $this->expectException(ValidationException::class);
        $servicio->finalizar($calidad, $empresa->Id_Empresa, $calificacion->Id_Calificacion_Recepcion);
    }

    public function test_una_calificacion_finalizada_no_se_puede_modificar(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);

        $servicio = $this->servicio();
        $id = $this->calificacionCompleta($servicio, $calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor);
        $servicio->finalizar($calidad, $empresa->Id_Empresa, $id);

        $parametro = RecepcionParametro::where('Activo', 1)->first();

        $this->expectException(ValidationException::class);
        $servicio->guardarRespuesta($calidad, $empresa->Id_Empresa, $id, $parametro->Id_Recepcion_Parametro, false, null);
    }

    /** "Una vez al año, máximo 2 veces por año." */
    public function test_no_admite_mas_de_dos_calificaciones_por_anio(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);

        $servicio = $this->servicio();

        for ($i = 0; $i < CalificacionRecepcionService::MAX_POR_ANIO; $i++) {
            $id = $this->calificacionCompleta($servicio, $calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor);
            $servicio->finalizar($calidad, $empresa->Id_Empresa, $id);
        }

        $this->expectException(ValidationException::class);
        $servicio->obtenerOCrearBorrador($calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor);
    }

    /** Un borrador abierto se retoma, no se duplica. */
    public function test_retoma_el_borrador_en_vez_de_crear_otro(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);

        $servicio = $this->servicio();
        $primera = $servicio->obtenerOCrearBorrador($calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor);
        $segunda = $servicio->obtenerOCrearBorrador($calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor);

        $this->assertSame($primera->Id_Calificacion_Recepcion, $segunda->Id_Calificacion_Recepcion);
        $this->assertSame(1, CalificacionRecepcion::where('Id_Proveedor', $proveedor->Id_Proveedor)->count());
    }

    public function test_compras_no_puede_calificar_recepciones(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $this->servicio()->obtenerOCrearBorrador($compras, $empresa->Id_Empresa, $proveedor->Id_Proveedor);
    }

    /**
     * Aislamiento entre empresas: con el id de una calificación de otra
     * empresa no se puede leer nada, ni teniendo rol en las dos.
     */
    public function test_no_se_puede_leer_una_calificacion_de_otra_empresa(): void
    {
        $empresaA = $this->crearEmpresa();
        $empresaB = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresaA, 'Calidad');
        // Mismo usuario con rol también en B, para que el corte no venga del rol.
        $this->crearUsuarioInterno($empresaB, 'Calidad');
        [, $proveedorB] = $this->crearProveedorConUsuario($empresaB);
        $this->crearEntrega($empresaB, $proveedorB);

        $calidadB = $this->crearUsuarioInterno($empresaB, 'Calidad');
        $deB = $this->servicio()->obtenerOCrearBorrador($calidadB, $empresaB->Id_Empresa, $proveedorB->Id_Proveedor);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->servicio()->obtenerDetalle($calidad, $empresaA->Id_Empresa, $deB->Id_Calificacion_Recepcion);
    }

    /**
     * Ya NO hay reparto por fórmula: la calificación se hace el día que el
     * proveedor entrega. Estos tests fijan las tres condiciones.
     */
    public function test_no_avisa_en_fin_de_semana_ni_feriado(): void
    {
        $agenda = app(AgendaRecepcionService::class);

        // Sábado y domingo.
        $this->assertFalse($agenda->esDiaDeAviso(Carbon::create(2026, 8, 22)));
        $this->assertFalse($agenda->esDiaDeAviso(Carbon::create(2026, 8, 23)));

        // 10 de agosto de 2026: Primer Grito de Independencia, y es lunes.
        $this->assertFalse($agenda->esDiaDeAviso(Carbon::create(2026, 8, 10)));

        // Un martes cualquiera sin feriado.
        $this->assertTrue($agenda->esDiaDeAviso(Carbon::create(2026, 8, 25)));
    }

    /**
     * Diciembre queda fuera: a esa altura del año todos los proveedores
     * tienen que estar ya calificados, así que no se generan avisos nuevos.
     */
    public function test_en_diciembre_no_se_generan_avisos(): void
    {
        $agenda = app(AgendaRecepcionService::class);

        // 1 de diciembre de 2026 es martes y no es feriado: lo único que lo
        // descarta es el mes.
        $diciembre = Carbon::create(2026, 12, 1);
        $this->assertFalse($diciembre->isWeekend());
        $this->assertFalse($agenda->esDiaDeAviso($diciembre));

        // 30 de noviembre de 2026 es lunes: ese sí entra.
        $this->assertTrue($agenda->esDiaDeAviso(Carbon::create(2026, 11, 30)));
    }

    /**
     * Entrega = calendario Y pedido (26-oct-2026). Con una sola de las dos no
     * le toca: el calendario dice que puede entregar, el pedido que trae algo.
     */
    public function test_solo_toca_si_tiene_calendario_y_pedido_ese_dia(): void
    {
        $empresa = $this->crearEmpresa();
        [, $conLasDos] = $this->crearProveedorConUsuario($empresa);
        [, $soloCalendario] = $this->crearProveedorConUsuario($empresa);
        [, $soloPedido] = $this->crearProveedorConUsuario($empresa);
        [, $sinEntrega] = $this->crearProveedorConUsuario($empresa);
        $agenda = app(AgendaRecepcionService::class);

        // Miércoles 26 de agosto de 2026, día laborable.
        $miercoles = Carbon::create(2026, 8, 26);
        $this->assertSame('Wednesday', $miercoles->format('l'));

        $this->crearEntrega($empresa, $conLasDos, $miercoles);
        $this->crearEntrega($empresa, $soloCalendario, $miercoles, pedido: false);
        $this->crearEntrega($empresa, $soloPedido, $miercoles, calendario: false);

        $tocan = $agenda->proveedoresQueTocanHoy($empresa->Id_Empresa, $miercoles)->pluck('Id_Proveedor');

        $this->assertTrue($tocan->contains($conLasDos->Id_Proveedor), 'Con calendario y pedido debe aparecer.');
        $this->assertFalse($tocan->contains($soloCalendario->Id_Proveedor), 'Solo con calendario NO debe aparecer.');
        $this->assertFalse($tocan->contains($soloPedido->Id_Proveedor), 'Solo con pedido NO debe aparecer.');
        $this->assertFalse($tocan->contains($sinEntrega->Id_Proveedor), 'Sin entrega NO debe aparecer.');
    }

    /** Como máximo 3 por día, en el orden del calendario (el que llega más temprano). */
    public function test_propone_como_maximo_tres_por_dia_en_orden_de_llegada(): void
    {
        $empresa = $this->crearEmpresa();
        $agenda = app(AgendaRecepcionService::class);
        $miercoles = Carbon::create(2026, 8, 26);

        $porHora = [];
        foreach (['10:00', '06:30', '09:15', '07:45', '08:00'] as $hora) {
            [, $proveedor] = $this->crearProveedorConUsuario($empresa);
            $this->crearEntrega($empresa, $proveedor, $miercoles, $hora);
            $porHora[$hora] = $proveedor->Id_Proveedor;
        }

        $tocan = $agenda->proveedoresQueTocanHoy($empresa->Id_Empresa, $miercoles)->pluck('Id_Proveedor')->map(fn ($id) => (int) $id)->all();

        $this->assertSame(AgendaRecepcionService::MAX_PROVEEDORES_POR_DIA, count($tocan));
        $this->assertSame([$porHora['06:30'], $porHora['07:45'], $porHora['08:00']], $tocan);
    }

    /** No se empieza una calificación de un proveedor que ese día no entrega. */
    public function test_no_se_inicia_una_calificacion_sin_entrega_ese_dia(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        // Calendario sí, pedido no.
        $this->crearEntrega($empresa, $proveedor, pedido: false);

        try {
            $this->servicio()->obtenerOCrearBorrador($calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor);
            $this->fail('Debió rechazarse por no tener pedido ese día.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no tiene pedidos', $e->errors()['proveedor'][0]);
        }

        $this->assertSame(0, CalificacionRecepcion::where('Id_Proveedor', $proveedor->Id_Proveedor)->count());
    }

    /**
     * Solo se listan, se proponen y se califican los proveedores con la
     * ficha aprobada y al menos un producto aprobado (26-oct-2026).
     */
    public function test_solo_entran_proveedores_con_ficha_y_producto_aprobados(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $habilitado] = $this->crearProveedorConUsuario($empresa);
        [, $sinFicha] = $this->crearProveedorConUsuario($empresa);
        [, $sinProducto] = $this->crearProveedorConUsuario($empresa);
        $agenda = app(AgendaRecepcionService::class);
        $miercoles = Carbon::create(2026, 8, 26);

        foreach ([$habilitado, $sinFicha, $sinProducto] as $proveedor) {
            $this->crearEntrega($empresa, $proveedor, $miercoles, habilitado: false);
        }
        $this->habilitarParaRecepcion($habilitado);
        $this->habilitarParaRecepcion($sinFicha, ficha: false);
        $this->habilitarParaRecepcion($sinProducto, producto: false);

        $tocan = $agenda->proveedoresQueTocanHoy($empresa->Id_Empresa, $miercoles)->pluck('Id_Proveedor')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([(int) $habilitado->Id_Proveedor], $tocan);

        $listados = $this->servicio()->listarProveedores($calidad, $empresa->Id_Empresa)->pluck('id_proveedor')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([(int) $habilitado->Id_Proveedor], $listados);

        // Se frena antes de mirar la entrega: el motivo es la ficha o el producto.
        foreach ([[$sinFicha, 'ficha no está aprobada'], [$sinProducto, 'ningún producto aprobado']] as [$proveedor, $motivo]) {
            try {
                $this->servicio()->obtenerOCrearBorrador($calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor);
                $this->fail('Debió rechazarse: '.$motivo);
            } catch (ValidationException $e) {
                $this->assertStringContainsString($motivo, $e->errors()['proveedor'][0]);
            }
        }
    }

    /** El aviso de documentos de la lista trae solo los que vencen en los próximos 30 días. */
    public function test_el_aviso_de_documentos_trae_solo_los_por_vencer(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $tipos = TipoDocumento::where('Activo', 1)->orderBy('Id_Tipo_Documento')->take(3)->get();
        $this->assertCount(3, $tipos);

        // Vence en 10 días (por vencer), en 90 (todavía no) y hace 5 (vencido).
        $fechas = [now()->addDays(10), now()->addDays(90), now()->subDays(5)];
        foreach ($tipos as $i => $tipo) {
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
            DocumentoProveedor::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
                'Id_Archivo' => $archivo->Id_Archivo,
                'Fecha_Caducidad' => $fechas[$i]->toDateString(),
                'Activo' => 1,
                'Creado_Por' => $usuario->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);
        }

        $pendientes = app(DocumentoProveedorService::class)->pendientesDeDocumentacion($proveedor);

        $this->assertSame([[
            'documento' => $tipos[0]->Nombre_Documento,
            'fecha_caducidad' => $fechas[0]->toDateString(),
        ]], $pendientes['por_vencer']);
        $this->assertSame([$tipos[2]->Nombre_Documento], $pendientes['vencidos']);
    }

    /** En 2026 la documentación incompleta no frena: se califica igual. */
    public function test_en_2026_se_califica_aunque_falten_documentos(): void
    {
        config()->set('portal.recepciones_exigen_documentos_desde', now()->addYear()->toDateString());

        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);

        $this->assertFalse($this->servicio()->exigeDocumentacion());
        $this->assertNotNull($this->servicio()->obtenerOCrearBorrador($calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor));
    }

    /** Desde 2027 no se califica a quien no subió sus documentos obligatorios. */
    public function test_desde_2027_no_se_califica_con_documentos_faltantes(): void
    {
        config()->set('portal.recepciones_exigen_documentos_desde', now()->subDay()->toDateString());

        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);

        $this->assertTrue($this->servicio()->exigeDocumentacion());

        try {
            $this->servicio()->obtenerOCrearBorrador($calidad, $empresa->Id_Empresa, $proveedor->Id_Proveedor);
            $this->fail('Debió rechazarse por documentación incompleta.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('le falta subir', $e->errors()['proveedor'][0]);
        }
    }

    /** La fecha por defecto del candado es el 1-ene-2027. */
    public function test_el_candado_de_documentos_arranca_el_1_de_enero_de_2027(): void
    {
        $servicio = $this->servicio();

        $this->assertFalse($servicio->exigeDocumentacion(Carbon::create(2026, 12, 31)));
        $this->assertTrue($servicio->exigeDocumentacion(Carbon::create(2027, 1, 1)));
    }

    /** Si ya tiene su calificación del año, deja de aparecer. */
    public function test_deja_de_avisar_cuando_ya_tiene_la_calificacion_del_anio(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $agenda = app(AgendaRecepcionService::class);

        $miercoles = Carbon::create(2026, 8, 26);

        $this->crearEntrega($empresa, $proveedor, $miercoles);

        $this->assertTrue($agenda->leTocaHoy($proveedor->Id_Proveedor, $empresa->Id_Empresa, $miercoles));

        // Se registra la del año, en otra fecha del mismo año.
        CalificacionRecepcion::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Usuario_Auditor' => $calidad->Id_Usuario,
            'Fecha_Recepcion' => Carbon::create(2026, 3, 11)->toDateString(),
            'Estado' => 'Finalizada',
            'Puntaje_Total_Posible' => 200,
            'Puntaje_Obtenido' => 180,
            'Porcentaje_Obtenido' => 90,
            'Fecha_Creacion' => now(),
        ]);

        $this->assertFalse(
            $agenda->leTocaHoy($proveedor->Id_Proveedor, $empresa->Id_Empresa, $miercoles),
            'Con la calificación del año ya hecha no debe volver a avisarse.'
        );
    }

    /** Deja un borrador con los 13 parámetros respondidos afirmativamente. */
    private function calificacionCompleta(
        CalificacionRecepcionService $servicio,
        $usuario,
        int $idEmpresa,
        int $idProveedor
    ): int {
        $calificacion = $servicio->obtenerOCrearBorrador($usuario, $idEmpresa, $idProveedor);
        $id = $calificacion->Id_Calificacion_Recepcion;

        foreach (RecepcionParametro::where('Activo', 1)->get() as $parametro) {
            $servicio->guardarRespuesta($usuario, $idEmpresa, $id, $parametro->Id_Recepcion_Parametro, true, null);
        }

        return $id;
    }
}
