<?php

namespace Tests\Feature\Proveedores;

use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Razón social y nombre comercial siempre en mayúsculas, y sin nombre
 * comercial vacío ni "N/A": se usa la razón social (16-oct-2026). Ver
 * Proveedor::booted.
 */
class NombresEnMayusculasTest extends TestCase
{
    private function datosSeccion1(Proveedor $proveedor, array $extra = []): array
    {
        return [
            'ruc' => $proveedor->Ruc,
            'clase_contribuyente' => (string) DB::table('Grupo_Impuesto_BC')->where('Activo', 1)->value('Codigo'),
            'razon_social' => 'papeles absorbentes la reforma s.a.',
            'nombre_comercial' => 'la reforma',
            'email' => $proveedor->Email,
            'telefono' => '022345678',
            'direccion' => 'Av. Siempre Viva 123',
            'ciudad' => 'Guayaquil',
            'latitud' => -2.18,
            'longitud' => -79.88,
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
            ...$extra,
        ];
    }

    public function test_la_ficha_guarda_los_nombres_en_mayusculas(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->putJson('/api/mi-ficha/seccion-1', $this->datosSeccion1($proveedor), $this->cabecerasComo($usuario, $empresa))
            ->assertOk()
            ->assertJsonPath('seccion_1.razon_social', 'PAPELES ABSORBENTES LA REFORMA S.A.')
            ->assertJsonPath('seccion_1.nombre_comercial', 'LA REFORMA');
    }

    /** Sin nombre comercial (vacío o "N/A"): se guarda y se muestra la razón social. */
    public function test_sin_nombre_comercial_se_usa_la_razon_social(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $cabeceras = $this->cabecerasComo($usuario, $empresa);

        foreach (['', null, 'N/A', ' n/a '] as $sinNombre) {
            $this->putJson('/api/mi-ficha/seccion-1', $this->datosSeccion1($proveedor, ['nombre_comercial' => $sinNombre]), $cabeceras)
                ->assertOk()
                ->assertJsonPath('seccion_1.nombre_comercial', 'PAPELES ABSORBENTES LA REFORMA S.A.');
        }
    }

    /** Vale para cualquier camino que guarde un proveedor, no solo la ficha. */
    public function test_cualquier_guardado_normaliza_aunque_no_pase_por_la_ficha(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, [
            'Razon_Social' => '  comercial   ñandú  y  peñas cía. ltda ',
            'Nombre_Comercial' => null,
        ]);

        $this->assertSame('COMERCIAL ÑANDÚ Y PEÑAS CÍA. LTDA', $proveedor->Razon_Social);
        $this->assertSame('COMERCIAL ÑANDÚ Y PEÑAS CÍA. LTDA', $proveedor->Nombre_Comercial);

        $proveedor->forceFill(['Nombre_Comercial' => 'el ñandú'])->save();
        $this->assertSame('EL ÑANDÚ', $proveedor->fresh()->Nombre_Comercial);
    }

    /** "N/A" en medio de un nombre real no se toca: solo cuando es TODO el nombre. */
    public function test_un_nombre_que_contiene_na_no_se_reemplaza(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Nombre_Comercial' => 'Distribuidora N/A Norte']);

        $this->assertSame('DISTRIBUIDORA N/A NORTE', $proveedor->Nombre_Comercial);
    }
}
