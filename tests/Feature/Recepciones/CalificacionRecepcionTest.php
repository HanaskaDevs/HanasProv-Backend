<?php

namespace Tests\Feature\Recepciones;

use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Auditorias\Models\RecepcionParametro;
use App\Modules\Auditorias\Services\AgendaRecepcionService;
use App\Modules\Auditorias\Services\CalificacionRecepcionService;
use App\Modules\Proveedores\Models\Proveedor;
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

    /** El reparto anual es estable y cae siempre en día hábil. */
    public function test_la_agenda_es_estable_y_en_dia_habil(): void
    {
        $agenda = app(AgendaRecepcionService::class);

        foreach ([1, 7, 25, 103, 998] as $idProveedor) {
            $primera = $agenda->fechaProgramada($idProveedor, 2026);
            $segunda = $agenda->fechaProgramada($idProveedor, 2026);

            $this->assertTrue($primera->equalTo($segunda), 'La fecha no puede cambiar entre llamadas.');
            $this->assertNotContains(
                $primera->dayOfWeek,
                [\Carbon\Carbon::SATURDAY, \Carbon\Carbon::SUNDAY],
                "La auditoría de {$idProveedor} cayó en fin de semana: ".$primera->toDateString()
            );
        }
    }

    /** Ids consecutivos NO deben caer todos juntos en el calendario. */
    public function test_la_agenda_reparte_los_proveedores_en_el_anio(): void
    {
        $agenda = app(AgendaRecepcionService::class);

        $meses = collect(range(1, 12))
            ->map(fn (int $id) => (int) $agenda->fechaProgramada($id, 2026)->month)
            ->unique();

        $this->assertGreaterThanOrEqual(10, $meses->count(), 'Con 12 proveedores deberían quedar repartidos en al menos 10 meses.');
    }

    public function test_detecta_a_quien_le_toca_hoy(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $agenda = app(AgendaRecepcionService::class);

        // Se viaja al día que le tocó a ESTE proveedor y se comprueba que aparece.
        $fecha = $agenda->fechaProgramada($proveedor->Id_Proveedor);
        $this->travelTo($fecha->copy()->setTime(9, 0));

        $this->assertTrue($agenda->leTocaHoy($proveedor->Id_Proveedor));
        $this->assertTrue(
            $agenda->proveedoresQueTocanHoy($empresa->Id_Empresa)
                ->contains(fn (Proveedor $p) => $p->Id_Proveedor === $proveedor->Id_Proveedor)
        );

        $this->travelTo($fecha->copy()->addDay()->setTime(9, 0));
        $this->assertFalse($agenda->leTocaHoy($proveedor->Id_Proveedor));

        $this->travelBack();
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
