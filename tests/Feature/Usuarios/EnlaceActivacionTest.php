<?php

namespace Tests\Feature\Usuarios;

use App\Models\Empresa;
use App\Modules\Auth\Models\BitacoraAcceso;
use App\Modules\Auth\Models\CodigoActivacion;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioEmpresa;
use App\Modules\Auth\Services\UsuarioService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Copiar enlace de activación" (05-oct-2026): para mandarle al proveedor
 * el enlace por WhatsApp o desde un Outlook propio cuando el correo
 * automático no le llega.
 *
 * EL ENLACE ES UNA CREDENCIAL: con él se activa la cuenta y se define la
 * contraseña. Lo que más se protege acá es a quién se le puede generar,
 * quién puede generarlo y que quede rastro.
 */
class EnlaceActivacionTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->empresa = $this->crearEmpresa();
    }

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

    private function pedirEnlace(Usuario $proveedor, Usuario $quien)
    {
        return $this->postJson(
            "/api/usuarios/{$proveedor->Id_Usuario}/enlace-activacion",
            [],
            $this->cabecerasComo($quien, $this->empresa)
        );
    }

    public function test_sistemas_obtiene_un_enlace_que_activa_la_cuenta(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $pendiente = $this->proveedor();

        $respuesta = $this->pedirEnlace($pendiente, $sistemas)->assertOk();

        $url = (string) $respuesta->json('url');
        $this->assertStringContainsString('/activar-cuenta?', $url);

        // El código del enlace es el que está VIGENTE en la base: si no,
        // el proveedor abriría un enlace que no sirve.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametros);
        $this->assertSame($pendiente->Email, $parametros['email']);
        $this->assertTrue(
            CodigoActivacion::where('Email', $pendiente->Email)
                ->where('Codigo', $parametros['codigo'])
                ->where('Usado', false)
                ->exists()
        );
    }

    /** Admin también: el pedido fue explícito. */
    public function test_admin_tambien_puede(): void
    {
        $admin = $this->crearUsuarioInterno($this->empresa, 'Admin');

        $this->pedirEnlace($this->proveedor(), $admin)->assertOk();
    }

    public function test_compras_no_puede(): void
    {
        $compras = $this->crearUsuarioInterno($this->empresa, 'Compras');

        $this->pedirEnlace($this->proveedor(), $compras)->assertForbidden();
    }

    /** Nunca se manda un correo: es justamente el camino alternativo. */
    public function test_no_manda_ningun_correo(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');

        $this->pedirEnlace($this->proveedor(), $sistemas)->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * Nunca dos credenciales vivas: el código que se le había mandado por
     * correo deja de servir.
     */
    public function test_el_codigo_anterior_deja_de_servir(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $pendiente = $this->proveedor();

        $viejo = CodigoActivacion::create([
            'Email' => $pendiente->Email,
            'Tipo' => 'Bienvenida',
            'Codigo' => 'VIEJ-1234',
            'Fecha_Expiracion' => now()->addDays(3),
            'Usado' => false,
            'Fecha_Creacion' => now(),
        ]);

        $this->pedirEnlace($pendiente, $sistemas)->assertOk();

        $this->assertTrue((bool) $viejo->fresh()->Usado);
    }

    /** A quien ya usa el portal no se le genera: le anularía su acceso. */
    public function test_no_se_genera_para_quien_ya_activo(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $activo = $this->proveedor(['Requiere_Cambio_Password' => false, 'Ultimo_Acceso' => now()]);

        $this->pedirEnlace($activo, $sistemas)->assertStatus(422);
    }

    /**
     * Un Admin de la empresa A no puede generarle credenciales a un
     * proveedor que solo trabaja con la empresa B, aunque tenga su id.
     */
    public function test_no_se_genera_para_un_proveedor_de_otra_empresa(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $ajeno = $this->proveedor([], $this->crearEmpresa());

        $this->pedirEnlace($ajeno, $sistemas)->assertForbidden();
    }

    /**
     * Queda rastro de QUIÉN lo generó y PARA QUIÉN. Se anota con el id de
     * quien lo generó para que no se pierda si la cuenta del proveedor se
     * elimina después.
     */
    public function test_queda_en_la_bitacora(): void
    {
        $sistemas = $this->crearUsuarioInterno($this->empresa, 'Sistemas');
        $pendiente = $this->proveedor();

        $this->pedirEnlace($pendiente, $sistemas)->assertOk();

        $this->assertTrue(
            BitacoraAcceso::where('Id_Usuario', $sistemas->Id_Usuario)
                ->where('Email_Intento', $pendiente->Email)
                ->where('Tipo_Evento', UsuarioService::EVENTO_ENLACE_ACTIVACION)
                ->exists()
        );
    }
}
