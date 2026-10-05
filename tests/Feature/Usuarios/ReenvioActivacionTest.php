<?php

namespace Tests\Feature\Usuarios;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioEmpresa;
use App\Modules\Auth\Notifications\CodigoActivacionNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reenvío del código de activación a proveedores que nunca entraron
 * (05-oct-2026), por dos caminos:
 *
 *  1. La carga por Excel: si el correo ya existe y nunca activó, se le
 *     reenvía el código en vez de saltearlo.
 *  2. El reenvío masivo desde Cuentas de proveedores, sobre los que
 *     Sistemas seleccione.
 *
 * Los dos usan LA MISMA regla de "pendiente de activación". Lo que más se
 * protege acá es que a quien YA usa el portal no le llegue nada: le
 * llegaría un correo que no pidió y le anularía el código vigente.
 */
class ReenvioActivacionTest extends TestCase
{
    private Empresa $empresa;

    private Usuario $sistemas;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->empresa = $this->crearEmpresa();
        $this->sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
    }

    /** Proveedor ya dado de alta, con el estado que se le pida. */
    private function proveedor(array $estado = [], ?Empresa $empresa = null): Usuario
    {
        $usuario = Usuario::create([
            'Email' => 'prov_'.Str::lower(Str::random(10)).'@test.local',
            'Password_Hash' => bcrypt('x'),
            'Nombre_Completo' => 'Proveedor',
            'Tipo_Usuario' => 'Proveedor',
            'Requiere_Cambio_Password' => true,
            'Activo' => true,
            'Fecha_Creacion' => now(),
            ...$estado,
        ]);

        UsuarioEmpresa::create([
            'Id_Usuario' => $usuario->Id_Usuario,
            'Id_Empresa' => ($empresa ?? $this->empresa)->Id_Empresa,
            'Id_Rol' => $this->idRol('Proveedor'),
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        return $usuario->fresh();
    }

    private function cargarExcel(array $correos)
    {
        $filas = array_map(fn (string $correo, int $i) => [
            'numero_fila' => $i + 2,
            'email' => $correo,
            'empresas' => [$this->empresa->Razon_Social],
        ], $correos, array_keys($correos));

        return $this->postJson(
            '/api/usuarios/externos/lote',
            ['filas' => $filas],
            $this->cabecerasComo($this->sistemas, $this->empresa)
        );
    }

    private function reenviarMasivo(array $ids, ?Usuario $quien = null)
    {
        return $this->postJson(
            '/api/usuarios/externos/reenviar-activacion-masivo',
            ['ids' => $ids],
            $this->cabecerasComo($quien ?? $this->sistemas, $this->empresa)
        );
    }

    // ================================================================
    // 1. Carga por Excel
    // ================================================================

    public function test_excel_un_correo_nuevo_se_crea_y_recibe_su_codigo(): void
    {
        $correo = 'nuevo_'.Str::lower(Str::random(8)).'@test.local';

        $respuesta = $this->cargarExcel([$correo])->assertOk();

        $this->assertSame('creado', $respuesta->json('filas.0.estado'));
        Notification::assertSentTo(Usuario::where('Email', $correo)->first(), CodigoActivacionNotification::class);
    }

    /** EL CAMBIO: antes se salteaba; ahora se le reenvía. */
    public function test_excel_a_quien_nunca_activo_se_le_reenvia_el_codigo(): void
    {
        $pendiente = $this->proveedor();

        $respuesta = $this->cargarExcel([$pendiente->Email])->assertOk();

        $this->assertSame('reenviado', $respuesta->json('filas.0.estado'));
        $this->assertSame(1, $respuesta->json('resumen.reenviados'));
        Notification::assertSentTo($pendiente, CodigoActivacionNotification::class);
    }

    public function test_excel_a_quien_ya_activo_no_se_le_manda_nada(): void
    {
        $activo = $this->proveedor(['Requiere_Cambio_Password' => false, 'Ultimo_Acceso' => now()]);

        $respuesta = $this->cargarExcel([$activo->Email])->assertOk();

        $this->assertSame('omitido', $respuesta->json('filas.0.estado'));
        $this->assertStringContainsString('Ya activó su cuenta', (string) $respuesta->json('filas.0.mensaje'));
        Notification::assertNotSentTo($activo, CodigoActivacionNotification::class);
    }

    /**
     * EL CASO SUTIL: Requiere_Cambio_Password vuelve a true cuando se le
     * reinicia la contraseña a alguien, aunque lleve meses usando el
     * portal. Sin mirar Ultimo_Acceso, el Excel le mandaría un código de
     * "bienvenida" y le anularía el que acababa de recibir para entrar.
     */
    public function test_excel_no_le_reenvia_a_quien_ya_ingreso_aunque_tenga_la_clave_reiniciada(): void
    {
        $usuario = $this->proveedor(['Requiere_Cambio_Password' => true, 'Ultimo_Acceso' => now()->subMonth()]);

        $this->cargarExcel([$usuario->Email])->assertOk();

        Notification::assertNotSentTo($usuario, CodigoActivacionNotification::class);
    }

    public function test_excel_a_una_cuenta_inactiva_no_se_le_reenvia_y_se_dice_por_que(): void
    {
        $inactivo = $this->proveedor(['Activo' => false]);

        $respuesta = $this->cargarExcel([$inactivo->Email])->assertOk();

        $this->assertStringContainsString('inactiva', (string) $respuesta->json('filas.0.mensaje'));
        Notification::assertNotSentTo($inactivo, CodigoActivacionNotification::class);
    }

    /** Las dos cosas en la misma fila: se le suma la empresa y se le reenvía. */
    public function test_excel_pendiente_en_otra_empresa_recibe_acceso_y_codigo(): void
    {
        $otraEmpresa = $this->crearEmpresa();
        $pendiente = $this->proveedor([], $otraEmpresa);

        $respuesta = $this->cargarExcel([$pendiente->Email])->assertOk();

        $this->assertSame('reenviado', $respuesta->json('filas.0.estado'));
        $this->assertStringContainsString('Se le agregó acceso', (string) $respuesta->json('filas.0.mensaje'));
        $this->assertTrue(
            UsuarioEmpresa::where('Id_Usuario', $pendiente->Id_Usuario)
                ->where('Id_Empresa', $this->empresa->Id_Empresa)
                ->exists()
        );
        Notification::assertSentTo($pendiente, CodigoActivacionNotification::class);
    }

    // ================================================================
    // 2. Reenvío masivo
    // ================================================================

    public function test_masivo_solo_les_llega_a_los_que_nunca_activaron(): void
    {
        $pendienteA = $this->proveedor();
        $pendienteB = $this->proveedor();
        $activo = $this->proveedor(['Requiere_Cambio_Password' => false, 'Ultimo_Acceso' => now()]);

        $respuesta = $this->reenviarMasivo([
            $pendienteA->Id_Usuario,
            $pendienteB->Id_Usuario,
            $activo->Id_Usuario,
        ])->assertOk();

        $this->assertSame(2, $respuesta->json('resumen.encolados'));
        $this->assertSame(1, $respuesta->json('resumen.omitidos'));

        Notification::assertSentTo($pendienteA, CodigoActivacionNotification::class);
        Notification::assertSentTo($pendienteB, CodigoActivacionNotification::class);
        Notification::assertNotSentTo($activo, CodigoActivacionNotification::class);
    }

    /**
     * Los ids los elige el navegador: un proveedor de OTRA empresa no puede
     * recibir códigos porque alguien escribió su número a mano.
     */
    public function test_masivo_ignora_proveedores_de_otra_empresa(): void
    {
        $ajeno = $this->proveedor([], $this->crearEmpresa());

        $respuesta = $this->reenviarMasivo([$ajeno->Id_Usuario])->assertOk();

        $this->assertSame(0, $respuesta->json('resumen.encolados'));
        $this->assertSame('No es un proveedor de esta empresa.', $respuesta->json('filas.0.mensaje'));
        Notification::assertNotSentTo($ajeno, CodigoActivacionNotification::class);
    }

    public function test_masivo_solo_lo_puede_hacer_sistemas(): void
    {
        $pendiente = $this->proveedor();
        $admin = $this->crearUsuarioInterno($this->empresa, 'Admin');

        $this->reenviarMasivo([$pendiente->Id_Usuario], $admin)->assertForbidden();

        Notification::assertNotSentTo($pendiente, CodigoActivacionNotification::class);
    }

    /**
     * EL ESPACIADO. El servidor de correo frena cuando salen muchos de
     * golpe ("450 too much mail") y esos avisos se pierden en failed_jobs.
     * Cada código tiene que salir unos segundos después del anterior.
     */
    public function test_masivo_escalona_los_envios(): void
    {
        config(['portal.correos.segundos_entre_envios' => 5]);

        $proveedores = [$this->proveedor(), $this->proveedor(), $this->proveedor()];

        $respuesta = $this->reenviarMasivo(array_map(fn ($p) => $p->Id_Usuario, $proveedores))->assertOk();

        $retrasos = array_map(function (Usuario $proveedor) {
            $retraso = null;

            Notification::assertSentTo($proveedor, CodigoActivacionNotification::class, function ($n) use (&$retraso) {
                $retraso = $n->delay;

                return true;
            });

            return $retraso === null ? 0 : now()->diffInSeconds($retraso, false);
        }, $proveedores);

        // El primero sale enseguida y cada uno después, ~5 s más tarde.
        $this->assertEqualsWithDelta(0, $retrasos[0], 1);
        $this->assertEqualsWithDelta(5, $retrasos[1], 1);
        $this->assertEqualsWithDelta(10, $retrasos[2], 1);

        $this->assertSame(1, $respuesta->json('minutos_estimados_envio'));
    }

    /** SQL Server admite 2100 parámetros por consulta. */
    public function test_masivo_tiene_tope_de_seleccion(): void
    {
        $this->reenviarMasivo(range(1, 201))->assertStatus(422);
    }
}
