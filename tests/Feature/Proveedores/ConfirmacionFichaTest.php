<?php

namespace Tests\Feature\Proveedores;

use App\Modules\Proveedores\Models\CategoriaProducto;
use App\Modules\Proveedores\Models\ClaseProveedor;
use App\Modules\Proveedores\Models\ConfirmacionFicha;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\FichaProveedorService;
use Tests\TestCase;

/**
 * Aceptación de las Políticas de Hanaska al enviar la ficha a revisión
 * (17-sep-2026).
 *
 * La ficha entra a revisión cuando llega al 100%, así que la casilla se
 * exige en el guardado que la completa. Estas pruebas cubren: que sin
 * aceptar no se guarda nada (ni la sección ni el 100%), que aceptando queda
 * la fila en Confirmacion_Ficha con la fecha de hoy, que no se duplica, y
 * que un guardado que NO completa la ficha no pide nada.
 */
class ConfirmacionFichaTest extends TestCase
{
    /** Mismos 19 campos que ProgresoFichaTest: con RUC y razón social suman los 22 obligatorios. */
    private function datosCompletosSeccion1(): array
    {
        return [
            'Clase_Contribuyente' => 'Sociedad',
            'Nombre_Comercial' => 'Comercial Test',
            'Telefono' => '022345678',
            'Direccion' => 'Av. Siempre Viva 123',
            'Ciudad' => 'Quito',
            'Latitud' => -0.18,
            'Longitud' => -78.46,
            'Representante_Legal' => 'Ana Torres',
            'Correo_Representante' => 'ana@test.local',
            'Telefono_Representante' => '0991111111',
            'Contacto_Venta' => 'Luis Vega',
            'Correo_Venta' => 'ventas@test.local',
            'Telefono_Contacto_Venta' => '0992222222',
            'Contacto_Calidad' => 'Sara Ruiz',
            'Correo_Calidad' => 'calidad@test.local',
            'Telefono_Contacto_Calidad' => '0993333333',
            'Contacto_Contabilidad' => 'Jose Paz',
            'Correo_Contabilidad' => 'conta@test.local',
            'Telefono_Contabilidad' => '0994444444',
        ];
    }

    /**
     * Proveedor con las secciones 1 y 2 completas: le falta solo la
     * categoría, así que el próximo guardado de la sección 3 completa la
     * ficha.
     *
     * @return array{0: \App\Modules\Auth\Models\Usuario, 1: \App\Models\Empresa, 2: Proveedor}
     */
    private function fichaAFaltaDeCategoria(): array
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $proveedor->forceFill($this->datosCompletosSeccion1())->save();
        $proveedor->clases()->attach(ClaseProveedor::where('Activo', 1)->value('Id_Clase_Proveedor'));

        return [$usuario, $empresa, $proveedor->fresh()];
    }

    private function idCategoria(): int
    {
        return (int) CategoriaProducto::where('Activo', 1)->value('Id_Categoria_Producto');
    }

    public function test_sin_aceptar_las_politicas_la_ficha_no_se_envia_a_revision(): void
    {
        [$usuario, $empresa, $proveedor] = $this->fichaAFaltaDeCategoria();

        $this->putJson('/api/mi-ficha/seccion-3', ['id_categorias' => [$this->idCategoria()]], $this->cabecerasComo($usuario, $empresa))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acepta_politicas' => FichaProveedorService::MENSAJE_POLITICAS_REQUERIDAS]);

        $proveedor->refresh();

        // No se guardó nada: la categoría no quedó y la ficha sigue por
        // debajo del 100%, así que no aparece en la cola del equipo.
        $this->assertSame(0, $proveedor->categoriasProducto()->count(), 'La categoría no debe guardarse si no aceptó.');
        $this->assertLessThan(100, (int) $proveedor->Porcentaje_Completado_Ficha);
        $this->assertFalse(ConfirmacionFicha::where('Id_Proveedor', $proveedor->Id_Proveedor)->exists());
    }

    public function test_aceptando_las_politicas_se_registra_la_confirmacion_con_la_fecha_de_hoy(): void
    {
        [$usuario, $empresa, $proveedor] = $this->fichaAFaltaDeCategoria();

        $ficha = $this->putJson(
            '/api/mi-ficha/seccion-3',
            ['id_categorias' => [$this->idCategoria()], 'acepta_politicas' => true],
            $this->cabecerasComo($usuario, $empresa)
        )->assertOk()->json();

        $this->assertSame(100, (int) $ficha['porcentaje_completado']);
        $this->assertSame(today()->format('Y-m-d'), $ficha['fecha_aceptacion_politicas']);

        $confirmacion = ConfirmacionFicha::where('Id_Proveedor', $proveedor->Id_Proveedor)->first();
        $this->assertNotNull($confirmacion, 'Debe quedar la fila en Confirmacion_Ficha.');
        $this->assertSame(today()->format('Y-m-d'), $confirmacion->Fecha_Confirmacion->format('Y-m-d'));
    }

    public function test_una_ficha_ya_confirmada_no_vuelve_a_pedir_aceptacion_ni_duplica_la_fila(): void
    {
        [$usuario, $empresa, $proveedor] = $this->fichaAFaltaDeCategoria();
        $cabeceras = $this->cabecerasComo($usuario, $empresa);

        $this->putJson('/api/mi-ficha/seccion-3', ['id_categorias' => [$this->idCategoria()], 'acepta_politicas' => true], $cabeceras)
            ->assertOk();

        // Vuelve a guardar la misma sección SIN la casilla (por ejemplo,
        // corrigiendo la categoría tras un rechazo): tiene que pasar.
        $this->putJson('/api/mi-ficha/seccion-3', ['id_categorias' => [$this->idCategoria()]], $cabeceras)
            ->assertOk();

        $this->assertSame(1, ConfirmacionFicha::where('Id_Proveedor', $proveedor->Id_Proveedor)->count());
    }

    public function test_un_guardado_que_no_completa_la_ficha_no_pide_aceptacion(): void
    {
        $empresa = $this->crearEmpresa();
        // Solo RUC y razón social: la sección 1 está incompleta, así que
        // guardar la categoría deja la ficha lejos del 100%.
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $ficha = $this->putJson('/api/mi-ficha/seccion-3', ['id_categorias' => [$this->idCategoria()]], $this->cabecerasComo($usuario, $empresa))
            ->assertOk()
            ->json();

        $this->assertLessThan(100, (int) $ficha['porcentaje_completado']);
        $this->assertNull($ficha['fecha_aceptacion_politicas']);
        $this->assertFalse(ConfirmacionFicha::where('Id_Proveedor', $proveedor->Id_Proveedor)->exists());
    }

    /** La sección 1 también puede ser la que completa la ficha (se llenan fuera de orden). */
    public function test_la_seccion_1_tambien_exige_la_aceptacion_si_es_la_que_completa_la_ficha(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $proveedor->clases()->attach(ClaseProveedor::where('Activo', 1)->value('Id_Clase_Proveedor'));
        $proveedor->categoriasProducto()->attach($this->idCategoria());

        $payload = [
            'ruc' => $proveedor->Ruc,
            'clase_contribuyente' => \App\Modules\Proveedores\Models\GrupoImpuestoBC::where('Activo', 1)->value('Codigo'),
            'razon_social' => $proveedor->Razon_Social,
            'nombre_comercial' => 'Comercial Test',
            'email' => $proveedor->Email,
            'telefono' => '022345678',
            'direccion' => 'Av. Siempre Viva 123',
            'ciudad' => 'Quito',
            'latitud' => -0.18,
            'longitud' => -78.46,
            'representante_legal' => 'Ana Torres',
            'correo_representante' => 'ana@test.local',
            'telefono_representante' => '0991111111',
            'contacto_venta' => 'Luis Vega',
            'correo_venta' => 'ventas@test.local',
            'telefono_contacto_venta' => '0992222222',
            'contacto_calidad' => 'Sara Ruiz',
            'correo_calidad' => 'calidad@test.local',
            'telefono_contacto_calidad' => '0993333333',
            'contacto_contabilidad' => 'Jose Paz',
            'correo_contabilidad' => 'conta@test.local',
            'telefono_contabilidad' => '0994444444',
        ];

        $cabeceras = $this->cabecerasComo($usuario, $empresa);

        $this->putJson('/api/mi-ficha/seccion-1', $payload, $cabeceras)
            ->assertStatus(422)
            ->assertJsonValidationErrors('acepta_politicas');

        $this->assertNull($proveedor->fresh()->Direccion, 'Sin aceptar, la sección 1 no debe guardarse.');

        $this->putJson('/api/mi-ficha/seccion-1', [...$payload, 'acepta_politicas' => true], $cabeceras)
            ->assertOk()
            ->assertJsonPath('porcentaje_completado', fn ($v) => (int) $v === 100)
            ->assertJsonPath('fecha_aceptacion_politicas', today()->format('Y-m-d'));
    }
}
