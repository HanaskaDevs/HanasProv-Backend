<?php

namespace Tests\Feature\Productos;

use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Shared\FormatoPrecio;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Precio del producto con hasta 4 decimales (18-nov-2026, pedido del
 * usuario). Antes Producto.Precio era DECIMAL(10,2) y un costo como 0,1275
 * se guardaba 0,13 (ver la migración 2026_11_18_090000).
 */
class PrecioCuatroDecimalesTest extends TestCase
{
    private function datos(array $extra = []): array
    {
        return [
            'nombre_producto' => 'Producto con precio fino',
            'clasificacion' => Producto::PERECIBLES,
            'id_unidad_presentacion' => (int) UnidadPresentacion::where('Activo', 1)->value('Id_Unidad_Presentacion'),
            ...$extra,
        ];
    }

    public function test_el_precio_se_guarda_con_cuatro_decimales(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Compras'), $empresa);

        $respuesta = $this->postJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            $this->datos(['precio' => 0.1275]),
            $cabeceras
        )->assertCreated();

        // La API lo devuelve completo, no redondeado a 0.13.
        $this->assertSame(0.1275, (float) $respuesta->json('precio'));

        $producto = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)->latest('Id_Producto')->first();
        $this->assertSame(0.1275, (float) $producto->Precio);

        // Y se puede corregir a otro valor con 4 decimales.
        $this->putJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}/{$producto->Id_Producto}",
            $this->datos(['precio' => '12.3456']),
            $cabeceras
        )->assertOk();

        $this->assertSame(12.3456, (float) $producto->fresh()->Precio);
    }

    public function test_un_precio_con_cinco_decimales_se_rechaza(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $cabeceras = $this->cabecerasComo($this->crearUsuarioInterno($empresa, 'Compras'), $empresa);

        $this->postJson(
            "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
            $this->datos(['precio' => '0.12345']),
            $cabeceras
        )->assertStatus(422)->assertJsonValidationErrors(['precio' => 'hasta 4 decimales']);

        // Los precios de siempre siguen entrando igual.
        foreach ([10, 10.5, '7.25', 0] as $precio) {
            $this->postJson(
                "/api/productos-proveedor/{$proveedor->Id_Proveedor}",
                $this->datos(['precio' => $precio]),
                $cabeceras
            )->assertCreated();
        }
    }

    public function test_la_solicitud_de_cambio_de_precio_tambien_admite_cuatro_decimales(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa, [
            'Id_Estado_Proveedor' => EstadoProveedor::APROBADO,
        ]);

        $producto = Producto::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Unidad_Presentacion' => $this->datos()['id_unidad_presentacion'],
            'Nombre_Producto' => 'CON PRECIO VIEJO',
            'Clasificacion' => Producto::PERECIBLES,
            'Precio' => 1.5,
            'Activo' => 1,
            'Bloqueado' => 0,
            'Fecha_Creacion' => now(),
        ]);

        $respuesta = $this->patchJson(
            "/api/mis-productos/{$producto->Id_Producto}/precio",
            ['precio_nuevo' => 1.5025],
            $this->cabecerasComo($usuario, $empresa)
        )->assertCreated();

        $this->assertSame(1.5025, (float) $respuesta->json('precio_nuevo'));
        $this->assertSame(1.5, (float) $respuesta->json('precio_anterior'));

        $this->patchJson(
            "/api/mis-productos/{$producto->Id_Producto}/precio",
            ['precio_nuevo' => 1.50251],
            $this->cabecerasComo($usuario, $empresa)
        )->assertStatus(422);
    }

    public function test_el_precio_se_muestra_con_dos_decimales_como_minimo_y_hasta_cuatro(): void
    {
        $this->assertSame('5.00', FormatoPrecio::mostrar('5.0000'));
        $this->assertSame('1.25', FormatoPrecio::mostrar(1.25));
        $this->assertSame('1.20', FormatoPrecio::mostrar('1.2'));
        $this->assertSame('0.1275', FormatoPrecio::mostrar('0.1275'));
        $this->assertSame('1.257', FormatoPrecio::mostrar(1.257));
        $this->assertSame('', FormatoPrecio::mostrar(null));
    }
}
