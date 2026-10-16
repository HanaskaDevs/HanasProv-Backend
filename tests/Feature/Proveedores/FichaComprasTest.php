<?php

namespace Tests\Feature\Proveedores;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Mail\FichaRegistradaMail;
use App\Modules\Proveedores\Models\CalificacionCampoFicha;
use App\Modules\Proveedores\Models\CategoriaProducto;
use App\Modules\Proveedores\Models\ClaseProveedor;
use App\Modules\Proveedores\Models\ConfirmacionFicha;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Responsables\Models\Responsable;
use App\Modules\Responsables\Models\ResponsableProveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Compras y la ficha del proveedor (16-oct-2026):
 *  - el aviso a Compras cuando un proveedor registra su ficha por primera
 *    vez (al responsable, o a todo Compras si no tiene);
 *  - la edición interna de la ficha mientras se revisa.
 *
 * Todo con Mail::fake(): nada sale de verdad.
 */
class FichaComprasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function datosSeccion1(Proveedor $proveedor, array $extra = []): array
    {
        return [
            'ruc' => $proveedor->Ruc,
            'clase_contribuyente' => (string) DB::table('Grupo_Impuesto_BC')->where('Activo', 1)->value('Codigo'),
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
            ...$extra,
        ];
    }

    private function idClase(): int
    {
        return (int) ClaseProveedor::where('Activo', 1)->value('Id_Clase_Proveedor');
    }

    private function idCategoria(): int
    {
        return (int) CategoriaProducto::where('Activo', 1)->value('Id_Categoria_Producto');
    }

    /** El proveedor completa las tres secciones y acepta las políticas en la última. */
    private function registrarFicha(Usuario $usuario, Empresa $empresa, Proveedor $proveedor): void
    {
        $cabeceras = $this->cabecerasComo($usuario, $empresa);

        $this->putJson('/api/mi-ficha/seccion-1', $this->datosSeccion1($proveedor), $cabeceras)->assertOk();
        $this->putJson('/api/mi-ficha/seccion-2', ['id_clases' => [$this->idClase()]], $cabeceras)->assertOk();
        $this->putJson('/api/mi-ficha/seccion-3', [
            'id_categorias' => [$this->idCategoria()],
            'acepta_politicas' => true,
        ], $cabeceras)->assertOk();
    }

    // ---------- Aviso de ficha registrada ----------

    /** Proveedor nuevo = sin código BC = sin responsable: le llega a todo Compras. */
    public function test_sin_responsable_el_aviso_va_a_todo_compras(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras1 = $this->crearUsuarioInterno($empresa, 'Compras');
        $compras2 = $this->crearUsuarioInterno($empresa, 'Compras');
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->registrarFicha($usuario, $empresa, $proveedor);

        Mail::assertQueued(FichaRegistradaMail::class, 1);
        Mail::assertQueued(FichaRegistradaMail::class, function (FichaRegistradaMail $mail) use ($compras1, $compras2, $calidad, $proveedor) {
            return $mail->hasTo($compras1->Email)
                && $mail->hasTo($compras2->Email)
                && ! $mail->hasTo($calidad->Email)
                && str_ends_with($mail->urlAccion, '/proveedores?calificar='.$proveedor->Id_Proveedor);
        });
    }

    public function test_con_responsable_el_aviso_va_solo_al_responsable(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $nroBc = 'PROV-T'.Str::upper(Str::random(6));
        $proveedor->forceFill(['Nro_Proveedor_BC' => $nroBc])->save();
        $responsable = Responsable::create([
            'Nombre' => 'Verónica Díaz',
            'Correo' => 'resp_'.Str::lower(Str::random(8)).'@hanaska.com',
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);
        ResponsableProveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Nro_Proveedor_BC' => $nroBc,
            'Id_Responsable' => $responsable->Id_Responsable,
            'Fecha_Creacion' => now(),
        ]);

        $this->registrarFicha($usuario, $empresa, $proveedor->fresh());

        Mail::assertQueued(FichaRegistradaMail::class, function (FichaRegistradaMail $mail) use ($responsable, $compras) {
            return $mail->hasTo($responsable->Correo)
                && ! $mail->hasTo($compras->Email)
                && $mail->nombreResponsable === 'Verónica Díaz';
        });
    }

    /** Solo la PRIMERA vez: una corrección posterior no vuelve a avisar. */
    public function test_volver_a_guardar_una_ficha_ya_registrada_no_avisa_de_nuevo(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearUsuarioInterno($empresa, 'Compras');

        $this->registrarFicha($usuario, $empresa, $proveedor);
        $this->putJson('/api/mi-ficha/seccion-3', ['id_categorias' => [$this->idCategoria()]], $this->cabecerasComo($usuario, $empresa))
            ->assertOk();

        Mail::assertQueued(FichaRegistradaMail::class, 1);
    }

    /** Los que ya habían registrado antes no reciben nada: Compras los ve entrando. */
    public function test_un_guardado_que_no_completa_la_ficha_no_avisa(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearUsuarioInterno($empresa, 'Compras');

        $this->putJson('/api/mi-ficha/seccion-1', $this->datosSeccion1($proveedor), $this->cabecerasComo($usuario, $empresa))
            ->assertOk();

        Mail::assertNotQueued(FichaRegistradaMail::class);
    }

    // ---------- Edición interna ----------

    public function test_compras_edita_la_ficha_sin_aceptar_politicas_ni_avisar(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        $cabeceras = $this->cabecerasComo($compras, $empresa);
        $url = "/api/proveedores/{$proveedor->Id_Proveedor}/ficha";

        $this->putJson("{$url}/seccion-1", $this->datosSeccion1($proveedor, ['nombre_comercial' => 'CORREGIDO POR COMPRAS']), $cabeceras)
            ->assertOk()
            ->assertJsonPath('seccion_1.nombre_comercial', 'CORREGIDO POR COMPRAS');
        $this->putJson("{$url}/seccion-2", ['id_clases' => [$this->idClase()]], $cabeceras)->assertOk();
        $ficha = $this->putJson("{$url}/seccion-3", ['id_categorias' => [$this->idCategoria()]], $cabeceras)
            ->assertOk()
            ->json();

        $proveedor->refresh();
        $this->assertSame($compras->Id_Usuario, (int) $proveedor->Modificado_Por);
        $this->assertSame(100, (int) $ficha['porcentaje_completado']);
        // La aceptación de políticas es del proveedor: nadie la firma por él.
        $this->assertFalse(ConfirmacionFicha::where('Id_Proveedor', $proveedor->Id_Proveedor)->exists());
        Mail::assertNotQueued(FichaRegistradaMail::class);
    }

    /** Corregir un campo rechazado lo deja "Sin calificar" para poder aprobarlo. */
    public function test_editar_un_campo_rechazado_lo_reabre_para_calificar(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        CalificacionCampoFicha::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nombre_Campo' => 'nombre_comercial',
            'Estado' => 'Rechazado',
            'Comentario' => 'Está mal escrito',
            'Calificado_Por' => $compras->Id_Usuario,
            'Fecha_Calificacion' => now(),
        ]);

        $this->putJson(
            "/api/proveedores/{$proveedor->Id_Proveedor}/ficha/seccion-1",
            $this->datosSeccion1($proveedor),
            $this->cabecerasComo($compras, $empresa)
        )->assertOk();

        $this->assertFalse(CalificacionCampoFicha::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Estado', 'Rechazado')
            ->exists());
    }

    /** Aprobado o Suspendido: ya está en Business Central, solo lectura. */
    public function test_la_ficha_de_un_proveedor_aprobado_no_se_edita(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        foreach ([EstadoProveedor::APROBADO, EstadoProveedor::SUSPENDIDO] as $estado) {
            [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Id_Estado_Proveedor' => $estado]);

            $this->putJson(
                "/api/proveedores/{$proveedor->Id_Proveedor}/ficha/seccion-2",
                ['id_clases' => [$this->idClase()]],
                $this->cabecerasComo($compras, $empresa)
            )->assertStatus(422);

            $this->getJson("/api/proveedores/{$proveedor->Id_Proveedor}/ficha-calificacion", $this->cabecerasComo($compras, $empresa))
                ->assertOk()
                ->assertJsonPath('editable_por_interno', false);
        }
    }

    public function test_guardia_y_proveedor_no_editan_la_ficha_de_otro(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuarioProveedor] = $this->crearProveedorConUsuario($empresa);
        [, $otro] = $this->crearProveedorConUsuario($empresa);

        foreach ([$this->crearUsuarioInterno($empresa, 'Guardia'), $usuarioProveedor] as $usuario) {
            $this->putJson(
                "/api/proveedores/{$otro->Id_Proveedor}/ficha/seccion-2",
                ['id_clases' => [$this->idClase()]],
                $this->cabecerasComo($usuario, $empresa)
            )->assertForbidden();
        }
    }

    public function test_no_se_edita_la_ficha_de_un_proveedor_de_otra_empresa(): void
    {
        $empresa = $this->crearEmpresa();
        $otraEmpresa = $this->crearEmpresa();
        [, $ajeno] = $this->crearProveedorConUsuario($otraEmpresa);
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->putJson(
            "/api/proveedores/{$ajeno->Id_Proveedor}/ficha/seccion-2",
            ['id_clases' => [$this->idClase()]],
            $this->cabecerasComo($compras, $empresa)
        )->assertNotFound();
    }
}
