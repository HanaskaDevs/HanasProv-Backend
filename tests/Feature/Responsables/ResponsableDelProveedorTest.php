<?php

namespace Tests\Feature\Responsables;

use App\Models\Empresa;
use App\Modules\Asistente\Services\AsistenteContextoService;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Responsables\Models\Responsable;
use App\Modules\Responsables\Models\ResponsableProveedor;
use App\Modules\Responsables\Services\ResponsableProveedorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A quién le escribe el proveedor cuando tiene una duda (02-oct-2026).
 *
 * La asignación se carga por código de proveedor de BC. Lo que más hay que
 * proteger acá es el CAMINO POR RUC: Nro_Proveedor_BC recién se llena
 * cuando el proveedor queda aprobado y se postea a BC, así que sin esa
 * búsqueda un proveedor en pleno registro no vería a su responsable
 * justamente cuando más preguntas tiene.
 */
class ResponsableDelProveedorTest extends TestCase
{
    private function empresaConBc(): Empresa
    {
        return $this->crearEmpresa(['Empresa_BC' => 'bc'.Str::lower(Str::random(6))]);
    }

    /** Una fila en la tabla espejo de BC: el proveedor ya existe allá. */
    private function fichaBc(Empresa $empresa, string $ruc): string
    {
        $nroBc = 'PROV-'.random_int(1000000, 9999999);

        DB::table('BC_Ficha_Proveedor')->insert([
            'Empresa' => trim($empresa->Empresa_BC),
            'Fecha_Registro' => now()->format('Y-m-d\TH:i:s'),
            'Nro_Proveedor' => $nroBc,
            'Nombre' => 'PROVEEDOR EN BC',
            'Nro_Identificacion' => $ruc,
        ]);

        return $nroBc;
    }

    private function responsable(array $extra = []): Responsable
    {
        return Responsable::create([
            'Nombre' => 'Verónica Díaz',
            'Correo' => 'resp_'.Str::lower(Str::random(8)).'@hanaska.com',
            'Telefono' => '0997306002',
            'Activo' => true,
            'Fecha_Creacion' => now(),
            ...$extra,
        ]);
    }

    private function asignar(Empresa $empresa, string $nroBc, Responsable $responsable): void
    {
        ResponsableProveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Nro_Proveedor_BC' => $nroBc,
            'Id_Responsable' => $responsable->Id_Responsable,
            'Fecha_Creacion' => now(),
        ]);
    }

    /**
     * EL CASO CENTRAL: proveedor Aspirante, sin Nro_Proveedor_BC todavía,
     * pero con su RUC en la tabla espejo de BC. Tiene que ver a su
     * responsable igual.
     */
    public function test_un_proveedor_sin_codigo_bc_lo_encuentra_por_su_ruc(): void
    {
        $empresa = $this->empresaConBc();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->assertNull($proveedor->Nro_Proveedor_BC, 'El escenario solo vale si todavía no tiene código.');

        $nroBc = $this->fichaBc($empresa, $proveedor->Ruc);
        $this->asignar($empresa, $nroBc, $this->responsable());

        $respuesta = $this->getJson('/api/mi-responsable', $this->cabecerasComo($usuario, $empresa));

        $respuesta->assertOk();
        $this->assertSame('Verónica Díaz', $respuesta->json('responsable.nombre'));
        $this->assertSame($nroBc, $respuesta->json('responsable.nro_proveedor_bc'));
        $this->assertSame('0997306002', $respuesta->json('responsable.telefono'));
    }

    /** Cuando ya tiene el código guardado, se usa ese y no hace falta el RUC. */
    public function test_con_el_codigo_bc_guardado_no_hace_falta_la_tabla_espejo(): void
    {
        $empresa = $this->empresaConBc();
        $nroBc = 'PROV-'.random_int(1000000, 9999999);

        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        // forceFill y no el create: Nro_Proveedor_BC no es asignable en
        // masa a propósito -lo escribe solo la integración con BC-.
        $proveedor->forceFill(['Nro_Proveedor_BC' => $nroBc])->save();

        $this->asignar($empresa, $nroBc, $this->responsable(['Nombre' => 'Olga Sotaminga']));

        $this->getJson('/api/mi-responsable', $this->cabecerasComo($usuario, $empresa))
            ->assertOk()
            ->assertJsonPath('responsable.nombre', 'Olga Sotaminga');
    }

    /** Sin asignación no se muestra nada: no hay correo genérico de relleno. */
    public function test_sin_asignacion_devuelve_null(): void
    {
        $empresa = $this->empresaConBc();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->fichaBc($empresa, $proveedor->Ruc);

        $this->getJson('/api/mi-responsable', $this->cabecerasComo($usuario, $empresa))
            ->assertOk()
            ->assertJsonPath('responsable', null);
    }

    /**
     * Un responsable dado de baja se trata como "sin responsable": mandar
     * al proveedor a escribirle a alguien que ya no está es peor que no
     * mostrar nada.
     */
    public function test_un_responsable_inactivo_no_se_muestra(): void
    {
        $empresa = $this->empresaConBc();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $nroBc = $this->fichaBc($empresa, $proveedor->Ruc);
        $this->asignar($empresa, $nroBc, $this->responsable(['Activo' => false]));

        $this->getJson('/api/mi-responsable', $this->cabecerasComo($usuario, $empresa))
            ->assertOk()
            ->assertJsonPath('responsable', null);
    }

    /**
     * Los códigos de BC los numera cada compañía por su cuenta: el mismo
     * PROV-0000056 puede ser de dos proveedores distintos. La asignación de
     * una empresa no puede filtrarse a la otra.
     */
    public function test_la_asignacion_no_cruza_de_empresa(): void
    {
        $empresaA = $this->empresaConBc();
        $empresaB = $this->empresaConBc();

        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresaB);
        $nroBc = $this->fichaBc($empresaB, $proveedor->Ruc);

        // La asignación existe, pero cargada en la OTRA empresa.
        $this->asignar($empresaA, $nroBc, $this->responsable());

        $this->getJson('/api/mi-responsable', $this->cabecerasComo($usuario, $empresa = $empresaB))
            ->assertOk()
            ->assertJsonPath('responsable', null);
    }

    /** Hana tiene que recibir el contacto dentro de su contexto. */
    public function test_el_asistente_recibe_el_contacto_en_su_contexto(): void
    {
        $empresa = $this->empresaConBc();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $nroBc = $this->fichaBc($empresa, $proveedor->Ruc);
        $this->asignar($empresa, $nroBc, $this->responsable(['Nombre' => 'Andrea Padilla']));

        $contexto = app(AsistenteContextoService::class)
            ->generar($proveedor->usuarios()->first(), $empresa->Id_Empresa);

        $this->assertStringContainsString('CONTACTO EN HANASKA', $contexto);
        $this->assertStringContainsString('Andrea Padilla', $contexto);
    }

    /**
     * Y sin responsable, el contexto tiene que PROHIBIRLE inventar uno:
     * sin esa instrucción el modelo ofrece un correo verosímil pero falso.
     */
    public function test_sin_responsable_al_asistente_se_le_prohibe_inventar(): void
    {
        $empresa = $this->empresaConBc();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $contexto = app(AsistenteContextoService::class)
            ->generar($proveedor->usuarios()->first(), $empresa->Id_Empresa);

        $this->assertStringContainsString('NO inventes', $contexto);
    }

    /**
     * El caso del despliegue a medias: el código sube pero la migración
     * todavía no corrió, así que las tablas no existen. El contexto tiene
     * que seguir armándose; si la excepción subiera, el try/catch de
     * AsistenteService haría que Hana conteste el mensaje de respaldo a
     * TODOS los proveedores por un dato de contacto que falta.
     */
    public function test_si_la_consulta_del_responsable_revienta_el_asistente_sigue_funcionando(): void
    {
        $empresa = $this->empresaConBc();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->app->bind(ResponsableProveedorService::class, function () {
            return new class extends ResponsableProveedorService
            {
                public function paraProveedor(Proveedor $proveedor): ?array
                {
                    throw new \RuntimeException('Invalid object name \'Responsable_Proveedor\'.');
                }
            };
        });

        $contexto = app(AsistenteContextoService::class)
            ->generar($proveedor->usuarios()->first(), $empresa->Id_Empresa);

        // El contexto se armó igual, con el resto de los bloques.
        $this->assertStringContainsString('DATOS DEL PROVEEDOR', $contexto);
        $this->assertStringNotContainsString('CONTACTO EN HANASKA', $contexto);
    }

    public function test_resolver_el_codigo_bc_ignora_el_ruc_de_otra_empresa(): void
    {
        $empresaA = $this->empresaConBc();
        $empresaB = $this->empresaConBc();

        [, $proveedor] = $this->crearProveedorConUsuario($empresaB);

        // El RUC está en BC, pero cargado bajo la otra compañía.
        $this->fichaBc($empresaA, $proveedor->Ruc);

        $this->assertNull(app(ResponsableProveedorService::class)->resolverNroBc($proveedor->fresh()));
    }
}
