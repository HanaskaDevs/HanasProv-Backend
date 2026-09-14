<?php

namespace Tests\Feature\Documentos;

use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Documentos_Proveedor\Services\DocumentoProveedorService;
use App\Modules\Proveedores\Models\Banco;
use App\Modules\Proveedores\Models\ProveedorCuentaBancaria;
use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\Proveedor;
use Tests\TestCase;

/**
 * El Certificado bancario no se da por cumplido con el PDF solo: hace
 * falta que el proveedor haya declarado banco, tipo de cuenta y número.
 *
 * Esos tres datos son los que se postean a la Ficha de Bancos de Business
 * Central al aprobarlo; sin ellos el proveedor queda registrado allá sin
 * cuenta donde pagarle, y eso se descubre recién al momento de pagar.
 */
class RegistroConDatosBancariosTest extends TestCase
{
    /** Carga un PDF para CADA tipo obligatorio que le aplica al proveedor. */
    private function cargarTodosLosObligatorios(Proveedor $proveedor, Usuario $usuario): void
    {
        $esQuito = strcasecmp((string) $proveedor->Ciudad, 'Quito') === 0;

        $tipos = TipoDocumento::where('Activo', 1)
            ->where('Obligatorio', 1)
            ->where(function ($query) use ($esQuito) {
                $query->where('Requiere_Solo_Quito', 0)->where('Requiere_Excepto_Quito', 0);
                if ($esQuito) {
                    $query->orWhere('Requiere_Solo_Quito', 1);
                } else {
                    $query->orWhere('Requiere_Excepto_Quito', 1);
                }
            })
            ->get();

        foreach ($tipos as $tipo) {
            $archivo = Archivo::create([
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Nombre_Original' => 'prueba.pdf',
                'Ruta_Almacenamiento' => 'pruebas/'.uniqid().'.pdf',
                'Hash_Archivo' => hash('sha256', uniqid('', true)),
                'Tipo_Mime' => 'application/pdf',
                'Tamano_Bytes' => 1024,
                'Categoria_Archivo' => 'prueba',
                'Id_Usuario_Carga' => $usuario->Id_Usuario,
                'Fecha_Carga' => now(),
                'Activo' => 1,
            ]);

            DocumentoProveedor::create([
                'Id_Archivo' => $archivo->Id_Archivo,
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
                'Estado' => 'Cargado',
                'Activo' => 1,
                'Fecha_Creacion' => now(),
            ]);
        }
    }

    private function declararCuenta(Proveedor $proveedor, Usuario $usuario): void
    {
        ProveedorCuentaBancaria::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Banco' => Banco::where('Activo', 1)->value('Id_Banco'),
            'Tipo_Cuenta' => 'AHO',
            'Nro_Cuenta' => '1234567890',
            'Registrado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
            'Fecha_Modificacion' => now(),
        ]);
    }

    /** @return array{0: Empresa, 1: Usuario, 2: Proveedor} */
    private function escenario(): array
    {
        $empresa = $this->crearEmpresa();
        // Ciudad distinta de Quito para no arrastrar el LUAE al escenario.
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Ciudad' => 'Guayaquil']);

        return [$empresa, $usuario, $proveedor];
    }

    public function test_no_se_registra_la_documentacion_sin_los_datos_de_la_cuenta(): void
    {
        [$empresa, $usuario, $proveedor] = $this->escenario();

        // Todos los PDF cargados, incluido el certificado bancario...
        $this->cargarTodosLosObligatorios($proveedor, $usuario);

        // ...pero sin declarar la cuenta.
        $respuesta = $this->postJson(
            '/api/mi-documentos/registrar',
            [],
            $this->cabecerasComo($usuario, $empresa)
        );

        $respuesta->assertStatus(422);
        $this->assertStringContainsString(
            'cuenta bancaria',
            (string) $respuesta->json('message')
        );

        // Y la documentación NO quedó registrada.
        $this->assertNull($proveedor->fresh()->Fecha_Registro_Documentacion);
    }

    public function test_con_los_datos_de_la_cuenta_si_se_registra(): void
    {
        [$empresa, $usuario, $proveedor] = $this->escenario();

        $this->cargarTodosLosObligatorios($proveedor, $usuario);
        $this->declararCuenta($proveedor, $usuario);

        $this->postJson('/api/mi-documentos/registrar', [], $this->cabecerasComo($usuario, $empresa))
            ->assertOk();

        $this->assertNotNull($proveedor->fresh()->Fecha_Registro_Documentacion);
    }

    public function test_sin_cuenta_el_endpoint_devuelve_null_y_no_un_objeto_vacio(): void
    {
        [$empresa, $usuario] = $this->escenario();

        $respuesta = $this->getJson('/api/mi-ficha/cuenta-bancaria', $this->cabecerasComo($usuario, $empresa));

        $respuesta->assertOk();

        /*
         * La clave del test: `response()->json(null)` de Laravel NO manda
         * null, manda `{}` (Symfony convierte el null en un ArrayObject
         * vacío). Como `{}` es truthy en JavaScript, la pantalla daba por
         * registrada una cuenta inexistente y dibujaba "Información de su
         * cuenta completa" con los tres campos en blanco.
         *
         * Por eso la respuesta va envuelta: acá se comprueba que "no
         * tiene cuenta" viaje como null de verdad.
         */
        $respuesta->assertExactJson(['cuenta' => null]);
        $this->assertNull($respuesta->json('cuenta'));
    }

    public function test_con_cuenta_el_endpoint_la_devuelve_envuelta(): void
    {
        [$empresa, $usuario, $proveedor] = $this->escenario();

        $this->declararCuenta($proveedor, $usuario);

        $respuesta = $this->getJson('/api/mi-ficha/cuenta-bancaria', $this->cabecerasComo($usuario, $empresa));

        $respuesta->assertOk();
        $this->assertSame('1234567890', $respuesta->json('cuenta.nro_cuenta'));
        $this->assertSame('AHO', $respuesta->json('cuenta.tipo_cuenta'));
        // El nombre del banco viaja resuelto; el Codigo_BC NUNCA sale al
        // front, es interno de la integración con Business Central.
        $this->assertNotNull($respuesta->json('cuenta.nombre_banco'));
        $this->assertArrayNotHasKey('codigo_bc', (array) $respuesta->json('cuenta'));
    }

    public function test_el_checklist_marca_cual_es_el_documento_que_pide_los_datos(): void
    {
        [$empresa, $usuario] = $this->escenario();

        $respuesta = $this->getJson('/api/mi-documentos', $this->cabecerasComo($usuario, $empresa));
        $respuesta->assertOk();

        $conDatos = collect($respuesta->json('documentos'))->where('requiere_datos_bancarios', true);

        // Exactamente uno, y es el del código CBANCARIO: la pantalla decide
        // por esta bandera dónde dibujar el panel, no por un id fijo.
        $this->assertCount(1, $conDatos, 'Solo el Certificado bancario debería pedir datos bancarios.');

        $idEsperado = TipoDocumento::where('Codigo_Archivo', DocumentoProveedorService::CODIGO_CERTIFICADO_BANCARIO)
            ->value('Id_Tipo_Documento');

        $this->assertEquals($idEsperado, $conDatos->first()['id_tipo_documento']);
    }
}
