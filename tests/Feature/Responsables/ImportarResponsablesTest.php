<?php

namespace Tests\Feature\Responsables;

use App\Models\Empresa;
use App\Modules\Responsables\Models\Responsable;
use App\Modules\Responsables\Models\ResponsableProveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Carga masiva del archivo de asignaciones (código de BC -> responsable).
 *
 * Va en dos pasos -validar y después aplicar- porque un archivo de
 * cientos de filas con la mitad mal no se puede deshacer a mano.
 */
class ImportarResponsablesTest extends TestCase
{
    private Empresa $empresa;

    private string $codigoA;

    private string $codigoB;

    private Responsable $veronica;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = $this->crearEmpresa(['Empresa_BC' => 'bc'.Str::lower(Str::random(6))]);
        $this->codigoA = $this->fichaBc('1111111111001');
        $this->codigoB = $this->fichaBc('2222222222001');

        $this->veronica = Responsable::create([
            'Nombre' => 'Verónica Díaz',
            'Correo' => 'vdiaz_'.Str::lower(Str::random(6)).'@hanaska.com',
            'Telefono' => '0997306002',
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);
    }

    private function fichaBc(string $ruc): string
    {
        $nroBc = 'PROV-'.random_int(1000000, 9999999);

        DB::table('BC_Ficha_Proveedor')->insert([
            'Empresa' => trim($this->empresa->Empresa_BC),
            'Fecha_Registro' => now()->format('Y-m-d\TH:i:s'),
            'Nro_Proveedor' => $nroBc,
            'Nombre' => 'PROVEEDOR '.$ruc,
            'Nro_Identificacion' => $ruc,
        ]);

        return $nroBc;
    }

    private function importar(array $filas, bool $aplicar = false)
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $ruta = $aplicar ? '/api/responsables/importar' : '/api/responsables/importar/validar';

        return $this->postJson($ruta, ['filas' => $filas], $this->cabecerasComo($sistemas, $this->empresa));
    }

    public function test_validar_no_guarda_nada(): void
    {
        $respuesta = $this->importar([
            ['fila' => 2, 'codigo_bc' => $this->codigoA, 'correo' => $this->veronica->Correo],
        ]);

        $respuesta->assertOk();
        $this->assertSame(1, $respuesta->json('nuevas'));
        $this->assertFalse($respuesta->json('aplicado'));
        $this->assertSame(0, ResponsableProveedor::where('Id_Empresa', $this->empresa->Id_Empresa)->count());
    }

    public function test_aplicar_crea_las_asignaciones(): void
    {
        $respuesta = $this->importar([
            ['fila' => 2, 'codigo_bc' => $this->codigoA, 'correo' => $this->veronica->Correo],
            ['fila' => 3, 'codigo_bc' => $this->codigoB, 'correo' => $this->veronica->Correo],
        ], aplicar: true);

        $respuesta->assertOk();
        $this->assertTrue($respuesta->json('aplicado'));
        $this->assertSame(2, $respuesta->json('nuevas'));
        $this->assertSame(2, ResponsableProveedor::where('Id_Empresa', $this->empresa->Id_Empresa)->count());
    }

    /** Volver a importar lo mismo no duplica ni cuenta como cambio. */
    public function test_reimportar_lo_mismo_no_cambia_nada(): void
    {
        $filas = [['fila' => 2, 'codigo_bc' => $this->codigoA, 'correo' => $this->veronica->Correo]];

        $this->importar($filas, aplicar: true)->assertOk();
        $respuesta = $this->importar($filas, aplicar: true)->assertOk();

        $this->assertSame(0, $respuesta->json('nuevas'));
        $this->assertSame(0, $respuesta->json('cambios'));
        $this->assertSame(1, $respuesta->json('sin_cambio'));
        $this->assertSame(1, ResponsableProveedor::where('Id_Empresa', $this->empresa->Id_Empresa)->count());
    }

    public function test_cambiar_de_responsable_se_reporta_como_cambio(): void
    {
        $this->importar(
            [['fila' => 2, 'codigo_bc' => $this->codigoA, 'correo' => $this->veronica->Correo]],
            aplicar: true
        );

        $olga = Responsable::create([
            'Nombre' => 'Olga Sotaminga',
            'Correo' => 'osotaminga_'.Str::lower(Str::random(6)).'@hanaska.com',
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        $respuesta = $this->importar(
            [['fila' => 2, 'codigo_bc' => $this->codigoA, 'correo' => $olga->Correo]],
            aplicar: true
        )->assertOk();

        $this->assertSame(1, $respuesta->json('cambios'));
        $this->assertSame(
            $olga->Id_Responsable,
            (int) ResponsableProveedor::where('Nro_Proveedor_BC', $this->codigoA)->value('Id_Responsable')
        );
    }

    /**
     * Un correo que no está en la lista de responsables NO crea una
     * persona al vuelo: un correo mal tipeado mandaría al proveedor a una
     * casilla inexistente.
     */
    public function test_un_correo_desconocido_es_error_y_no_crea_responsables(): void
    {
        $antes = Responsable::count();

        $respuesta = $this->importar([
            ['fila' => 2, 'codigo_bc' => $this->codigoA, 'correo' => 'notengoidea@hanaska.com'],
        ])->assertOk();

        $this->assertSame(1, count($respuesta->json('errores')));
        $this->assertStringContainsString('lista de responsables', $respuesta->json('errores.0.motivo'));
        $this->assertSame($antes, Responsable::count());
    }

    /** Un código que BC no conoce no se va a poder atar a nadie nunca. */
    public function test_un_codigo_que_no_existe_en_bc_es_error(): void
    {
        $respuesta = $this->importar([
            ['fila' => 2, 'codigo_bc' => 'PROV-9999999', 'correo' => $this->veronica->Correo],
        ])->assertOk();

        $this->assertStringContainsString('no existe en BC', $respuesta->json('errores.0.motivo'));
        $this->assertSame(0, $respuesta->json('nuevas'));
    }

    public function test_un_codigo_con_formato_invalido_es_error(): void
    {
        $respuesta = $this->importar([
            ['fila' => 2, 'codigo_bc' => '56', 'correo' => $this->veronica->Correo],
        ])->assertOk();

        $this->assertStringContainsString('formato de BC', $respuesta->json('errores.0.motivo'));
    }

    /** Dos filas con el mismo código: no hay forma de saber cuál vale. */
    public function test_un_codigo_repetido_en_el_archivo_es_error(): void
    {
        $respuesta = $this->importar([
            ['fila' => 2, 'codigo_bc' => $this->codigoA, 'correo' => $this->veronica->Correo],
            ['fila' => 3, 'codigo_bc' => $this->codigoA, 'correo' => $this->veronica->Correo],
        ])->assertOk();

        $this->assertSame(1, $respuesta->json('nuevas'));
        $this->assertStringContainsString('repetido', $respuesta->json('errores.0.motivo'));
    }

    /** Las filas con error no frenan a las buenas: se aplica lo que sirve. */
    public function test_las_filas_buenas_se_aplican_aunque_otras_fallen(): void
    {
        $respuesta = $this->importar([
            ['fila' => 2, 'codigo_bc' => $this->codigoA, 'correo' => $this->veronica->Correo],
            ['fila' => 3, 'codigo_bc' => 'PROV-9999999', 'correo' => $this->veronica->Correo],
        ], aplicar: true)->assertOk();

        $this->assertSame(1, $respuesta->json('nuevas'));
        $this->assertSame(1, count($respuesta->json('errores')));
        $this->assertSame(1, ResponsableProveedor::where('Id_Empresa', $this->empresa->Id_Empresa)->count());
    }

    public function test_solo_sistemas_puede_importar(): void
    {
        $compras = $this->crearUsuarioInterno($this->empresa, 'Compras');

        $this->postJson(
            '/api/responsables/importar',
            ['filas' => [['fila' => 2, 'codigo_bc' => $this->codigoA, 'correo' => $this->veronica->Correo]]],
            $this->cabecerasComo($compras, $this->empresa)
        )->assertForbidden();
    }

    /** Con proveedores asignados no se borra: se desactiva. */
    public function test_no_se_puede_eliminar_un_responsable_con_proveedores(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $this->importar(
            [['fila' => 2, 'codigo_bc' => $this->codigoA, 'correo' => $this->veronica->Correo]],
            aplicar: true
        );

        $this->deleteJson(
            "/api/responsables/{$this->veronica->Id_Responsable}",
            [],
            $this->cabecerasComo($sistemas, $this->empresa)
        )->assertStatus(422);
    }
}
