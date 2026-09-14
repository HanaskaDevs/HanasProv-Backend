<?php

namespace Tests\Feature\Proveedores;

use App\Modules\Proveedores\Models\CategoriaProducto;
use App\Modules\Proveedores\Models\ClaseProveedor;
use App\Modules\Proveedores\Services\FichaProveedorService;
use Tests\TestCase;

/**
 * Cuándo se considera COMPLETA la ficha de un proveedor.
 *
 * Guarda contra el fallo del 2-sep-2026: la comprobación de la sección 1
 * miraba solo el RUC y la razón social. Desde que la activación de la cuenta
 * pide esos dos datos por adelantado, un proveedor recién creado que elegía
 * su clase y su categoría saltaba al 100%: la ficha aparecía «pendiente de
 * revisión» y BLOQUEADA para editar, sin dirección, sin teléfonos, sin
 * contactos y sin ubicación en el mapa.
 */
class ProgresoFichaTest extends TestCase
{
    /** Los 22 campos que la sección 1 exige de verdad (ver GuardarSeccion1Request). */
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

    public function test_solo_con_ruc_y_razon_social_la_seccion_1_no_esta_completa(): void
    {
        $empresa = $this->crearEmpresa();
        // crearProveedorConUsuario ya deja Ruc y Razon_Social cargados, que
        // es exactamente el estado en que queda una cuenta recién activada.
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->assertNotNull($proveedor->Ruc);
        $this->assertNotNull($proveedor->Razon_Social);

        $this->assertFalse(
            FichaProveedorService::seccion1EstaCompleta($proveedor),
            'Con solo RUC y razón social la sección 1 NO puede darse por completa: faltan 20 campos.'
        );
    }

    public function test_con_todos_los_campos_la_seccion_1_esta_completa(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $proveedor->forceFill($this->datosCompletosSeccion1())->save();

        $this->assertTrue(FichaProveedorService::seccion1EstaCompleta($proveedor->fresh()));
    }

    /** Falta UN solo campo -> no está completa. Se prueba con varios. */
    public function test_si_falta_cualquier_campo_obligatorio_no_esta_completa(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $proveedor->forceFill($this->datosCompletosSeccion1())->save();

        foreach (['Direccion', 'Latitud', 'Contacto_Calidad', 'Telefono'] as $campo) {
            $proveedor->forceFill([$campo => null])->save();

            $this->assertFalse(
                FichaProveedorService::seccion1EstaCompleta($proveedor->fresh()),
                "Sin {$campo} la sección 1 no puede estar completa."
            );

            // Se repone para probar el siguiente campo por separado.
            $proveedor->forceFill($this->datosCompletosSeccion1())->save();
        }
    }

    /**
     * El caso exacto que se reportó: cuenta recién activada (RUC y razón
     * social puestos) que elige clase y categoría. Antes esto daba 100% y
     * bloqueaba la ficha; ahora tiene que quedar por debajo.
     */
    public function test_una_ficha_recien_activada_con_clase_y_categoria_no_llega_al_100(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $proveedor->clases()->attach(ClaseProveedor::where('Activo', 1)->value('Id_Clase_Proveedor'));
        $proveedor->categoriasProducto()->attach(CategoriaProducto::where('Activo', 1)->value('Id_Categoria_Producto'));

        $ficha = $this->getJson('/api/mi-ficha', $this->cabecerasComo($usuario, $empresa))
            ->assertOk()
            ->json();

        $this->assertFalse($ficha['seccion_1_completa']);
        $this->assertLessThan(
            100,
            (int) $ficha['porcentaje_completado'],
            'La ficha no puede estar al 100% sin los datos generales cargados.'
        );
        $this->assertSame(1, (int) $ficha['seccion_actual'], 'Debe mandarlo a completar la sección 1.');
    }
}
