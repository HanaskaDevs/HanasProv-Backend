<?php

namespace Tests\Feature\Reclamos;

use App\Modules\Reclamos\Services\ReclamoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CrearReclamoTest extends TestCase
{
    /** @return array<int, UploadedFile> */
    private function imagenesFalsas(int $cantidad): array
    {
        // create() y no image(): estos archivos nunca se leen (el tope se
        // valida por cantidad, antes de tocar el disco), así que no hace
        // falta que sean imágenes de verdad ni depender de la extensión GD.
        return array_map(
            fn (int $i) => UploadedFile::fake()->create("foto{$i}.jpg", 10),
            range(1, $cantidad)
        );
    }

    private function datosBase(): array
    {
        return [
            'asunto' => 'Producto en mal estado',
            'tipoReclamo' => 'Calidad',
            'impactoProveedor' => 'Alto',
            'mensajeTexto' => 'Llegó un lote con empaques rotos.',
        ];
    }

    public function test_un_usuario_interno_crea_un_reclamo_con_su_primer_mensaje(): void
    {
        $empresa = $this->crearEmpresa();
        $interno = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $datos = $this->datosBase();

        $reclamo = app(ReclamoService::class)->crear(
            $interno,
            $empresa->Id_Empresa,
            $proveedor->Id_Proveedor,
            $datos['asunto'],
            $datos['tipoReclamo'],
            $datos['impactoProveedor'],
            $datos['mensajeTexto'],
            [['rol_contacto' => 'Ventas', 'nombre_contacto' => 'Ana', 'email' => 'ventas@test.local']],
        );

        $this->assertSame('Abierto', $reclamo->Estado);
        $this->assertCount(1, $reclamo->destinatarios);

        // Todo el texto del reclamo se guarda EN MAYÚSCULAS, tal como se
        // escriba (decisión del negocio, 1-sep-2026, ver
        // ReclamoService::aMayusculas). Se comprueba con mb_strtoupper y no
        // con un literal para que el test siga valiendo si mañana cambia el
        // texto de ejemplo.
        $this->assertSame(mb_strtoupper($datos['asunto'], 'UTF-8'), $reclamo->Asunto);
        $this->assertSame(
            mb_strtoupper($datos['mensajeTexto'], 'UTF-8'),
            $reclamo->mensajes()->first()->Mensaje
        );
    }

    /**
     * REGRESIÓN: pasarse del tope de imágenes tiene que devolver un mensaje
     * LEGIBLE. Antes crear() lanzaba un ValidationException armado con un
     * validador vacío -> llegaba al front un 422 sin ningún error adentro,
     * y el formulario fallaba sin decir por qué.
     */
    public function test_pasarse_del_tope_de_imagenes_da_un_mensaje_entendible(): void
    {
        $empresa = $this->crearEmpresa();
        $interno = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $datos = $this->datosBase();

        try {
            app(ReclamoService::class)->crear(
                $interno,
                $empresa->Id_Empresa,
                $proveedor->Id_Proveedor,
                $datos['asunto'],
                $datos['tipoReclamo'],
                $datos['impactoProveedor'],
                $datos['mensajeTexto'],
                [['rol_contacto' => 'Ventas', 'nombre_contacto' => null, 'email' => 'ventas@test.local']],
                $this->imagenesFalsas(6),
            );

            $this->fail('Se esperaba un ValidationException por pasarse del tope de imágenes.');
        } catch (ValidationException $e) {
            $mensajes = $e->validator->errors()->all();

            $this->assertNotEmpty($mensajes, 'El error llegó SIN mensajes (el bug original).');
            $this->assertStringContainsString('imágenes', $mensajes[0]);
        }
    }

    public function test_un_proveedor_no_puede_crear_reclamos(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $datos = $this->datosBase();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

        app(ReclamoService::class)->crear(
            $usuarioProveedor,
            $empresa->Id_Empresa,
            $proveedor->Id_Proveedor,
            $datos['asunto'],
            $datos['tipoReclamo'],
            $datos['impactoProveedor'],
            $datos['mensajeTexto'],
            [['rol_contacto' => 'Ventas', 'nombre_contacto' => null, 'email' => 'ventas@test.local']],
        );
    }

    /** Solo quien lo creó puede cerrarlo. */
    public function test_otro_interno_no_puede_cerrar_un_reclamo_ajeno(): void
    {
        $empresa = $this->crearEmpresa();
        $creador = $this->crearUsuarioInterno($empresa, 'Compras');
        $otro = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $datos = $this->datosBase();
        $servicio = app(ReclamoService::class);

        $reclamo = $servicio->crear(
            $creador,
            $empresa->Id_Empresa,
            $proveedor->Id_Proveedor,
            $datos['asunto'],
            $datos['tipoReclamo'],
            $datos['impactoProveedor'],
            $datos['mensajeTexto'],
            [['rol_contacto' => 'Ventas', 'nombre_contacto' => null, 'email' => 'ventas@test.local']],
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

        $servicio->cerrar($otro, $empresa->Id_Empresa, $reclamo->Id_Reclamo);
    }
}
