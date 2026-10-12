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
 * Admin puede desbloquear proveedores trabados por contraseñas
 * incorrectas (12-oct-2026). Antes solo Sistemas: Admin veía la opción
 * en el menú y el servidor se la rechazaba.
 *
 * Desbloquear (la cuenta se trabó SOLA) y reactivar (alguien la inactivó
 * A PROPÓSITO) siguen siendo permisos distintos: lo segundo sigue siendo
 * solo de Sistemas. Eso es lo que más se protege acá.
 */
class DesbloqueoPorAdminTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->empresa = $this->crearEmpresa();
    }

    private function proveedor(array $estado = [], ?Empresa $empresa = null, string $tipo = 'Proveedor'): Usuario
    {
        $usuario = Usuario::create([
            'Email' => 'prov_'.Str::lower(Str::random(10)).'@test.local',
            'Password_Hash' => bcrypt('x'),
            'Nombre_Completo' => 'Proveedor',
            'Tipo_Usuario' => $tipo,
            'Requiere_Cambio_Password' => false,
            'Activo' => true,
            'Bloqueado_Por_Intentos' => true,
            'Fecha_Bloqueo' => now(),
            'Fecha_Creacion' => now(),
            ...$estado,
        ]);

        UsuarioEmpresa::create([
            'Id_Usuario' => $usuario->Id_Usuario,
            'Id_Empresa' => ($empresa ?? $this->empresa)->Id_Empresa,
            'Id_Rol' => $this->idRol($tipo === 'Proveedor' ? 'Proveedor' : 'Compras'),
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        return $usuario->fresh();
    }

    private function desbloquear(Usuario $objetivo, Usuario $quien)
    {
        return $this->patchJson(
            "/api/usuarios/{$objetivo->Id_Usuario}/reactivar",
            [],
            $this->cabecerasComo($quien, $this->empresa)
        );
    }

    public function test_admin_desbloquea_a_un_proveedor_trabado_por_contrasenas(): void
    {
        $admin = $this->crearUsuarioInterno($this->empresa, 'Admin');
        $bloqueado = $this->proveedor();

        $this->desbloquear($bloqueado, $admin)->assertOk();

        $this->assertFalse((bool) $bloqueado->fresh()->Bloqueado_Por_Intentos);
        // Y se le manda un código para que defina una contraseña nueva.
        Notification::assertSentTo($bloqueado, CodigoActivacionNotification::class);
    }

    /**
     * El desbloqueo NO espera al correo: contesta al instante y el envío
     * se sigue en vivo. Era lo que hacía esperar a quien apretó el botón.
     */
    public function test_el_desbloqueo_contesta_sin_esperar_al_correo(): void
    {
        $admin = $this->crearUsuarioInterno($this->empresa, 'Admin');

        $respuesta = $this->desbloquear($this->proveedor(), $admin)->assertOk();

        $this->assertSame('enviando', $respuesta->json('estado'));
        $this->assertNotNull($respuesta->json('id_codigo'));
    }

    /** Reactivar una cuenta inactivada a propósito sigue siendo de Sistemas. */
    public function test_admin_no_puede_reactivar_una_cuenta_inactivada(): void
    {
        $admin = $this->crearUsuarioInterno($this->empresa, 'Admin');
        $inactivada = $this->proveedor(['Activo' => false, 'Bloqueado_Por_Intentos' => false]);

        $this->desbloquear($inactivada, $admin)->assertForbidden();

        $this->assertFalse((bool) $inactivada->fresh()->Activo);
    }

    /** Inactivada Y además trabada: sigue mandando la inactivación. */
    public function test_admin_no_puede_si_ademas_esta_inactivada(): void
    {
        $admin = $this->crearUsuarioInterno($this->empresa, 'Admin');
        $ambas = $this->proveedor(['Activo' => false, 'Bloqueado_Por_Intentos' => true]);

        $this->desbloquear($ambas, $admin)->assertForbidden();
    }

    public function test_admin_no_puede_desbloquear_proveedores_de_otra_empresa(): void
    {
        $admin = $this->crearUsuarioInterno($this->empresa, 'Admin');
        $ajeno = $this->proveedor([], $this->crearEmpresa());

        $this->desbloquear($ajeno, $admin)->assertForbidden();

        $this->assertTrue((bool) $ajeno->fresh()->Bloqueado_Por_Intentos);
    }

    /** Las cuentas del personal interno siguen siendo cosa de Sistemas. */
    public function test_admin_no_puede_desbloquear_a_un_usuario_interno(): void
    {
        $admin = $this->crearUsuarioInterno($this->empresa, 'Admin');
        $interno = $this->proveedor([], null, 'Interno');

        $this->desbloquear($interno, $admin)->assertForbidden();
    }

    public function test_sistemas_sigue_pudiendo_todo(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $inactivada = $this->proveedor(['Activo' => false, 'Bloqueado_Por_Intentos' => false]);

        $this->desbloquear($inactivada, $sistemas)->assertOk();

        $this->assertTrue((bool) $inactivada->fresh()->Activo);
    }

    public function test_compras_no_puede_desbloquear(): void
    {
        $compras = $this->crearUsuarioInterno($this->empresa, 'Compras');

        $this->desbloquear($this->proveedor(), $compras)->assertForbidden();
    }
}
