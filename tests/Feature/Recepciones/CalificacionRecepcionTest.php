<?php

namespace Tests\Feature\Recepciones;

use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Auditorias\Models\RecepcionParametro;
use App\Modules\Auditorias\Services\AgendaRecepcionService;
use App\Modules\Auditorias\Services\CalificacionRecepcionService;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\Proveedor;
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

    public function test_solo_toca_si_el_proveedor_tiene_entrega_ese_dia(): void
    {
        $empresa = $this->crearEmpresa();
        [, $conEntrega] = $this->crearProveedorConUsuario($empresa);
        [, $sinEntrega] = $this->crearProveedorConUsuario($empresa);
        $agenda = app(AgendaRecepcionService::class);

        // Miércoles 26 de agosto de 2026, día laborable.
        $miercoles = Carbon::create(2026, 8, 26);
        $this->assertSame('Wednesday', $miercoles->format('l'));

        HorarioEntregaProveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $conEntrega->Id_Proveedor,
            'Clasificacion' => 'Fruver',
            'Dia_Entrega' => 'Miercoles',
            'Hora_Llegada' => '07:00',
            'Activo' => 1,
            'Fecha_Creacion' => now(),
        ]);

        $tocan = $agenda->proveedoresQueTocanHoy($empresa->Id_Empresa, $miercoles)->pluck('Id_Proveedor');

        $this->assertTrue($tocan->contains($conEntrega->Id_Proveedor), 'El que entrega el miércoles debe aparecer.');
        $this->assertFalse($tocan->contains($sinEntrega->Id_Proveedor), 'El que no entrega ese día NO debe aparecer.');
    }

    /** Un pedido con fecha de recepción ese día también cuenta como entrega. */
    public function test_un_pedido_con_recepcion_ese_dia_tambien_cuenta(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $agenda = app(AgendaRecepcionService::class);

        $martes = Carbon::create(2026, 8, 25);

        PedidoCompra::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nro_Pedido' => 'TEST-'.random_int(100000, 999999),
            'Fecha_Registro_BC' => $martes->copy()->subDays(5)->toDateString(),
            'Fecha_Recepcion_Esperada' => $martes->toDateString(),
            'Fecha_Sincronizacion' => now(),
            'Activo' => 1,
            'Estado' => 'Abierto',
        ]);

        $this->assertTrue(
            $agenda->proveedoresQueTocanHoy($empresa->Id_Empresa, $martes)
                ->contains(fn (Proveedor $p) => $p->Id_Proveedor === $proveedor->Id_Proveedor)
        );
    }

    /** Si ya tiene su calificación del año, deja de aparecer. */
    public function test_deja_de_avisar_cuando_ya_tiene_la_calificacion_del_anio(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $agenda = app(AgendaRecepcionService::class);

        $miercoles = Carbon::create(2026, 8, 26);

        HorarioEntregaProveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => 'Fruver',
            'Dia_Entrega' => 'Miercoles',
            'Hora_Llegada' => '07:00',
            'Activo' => 1,
            'Fecha_Creacion' => now(),
        ]);

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
