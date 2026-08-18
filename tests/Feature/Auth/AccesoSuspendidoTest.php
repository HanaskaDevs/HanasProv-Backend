<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Proveedores\Models\EstadoProveedor;
use Tests\TestCase;

/**
 * Qué pasa cuando a un proveedor le suspenden el acceso por documentación
 * vencida. La regla es POR EMPRESA: solo se le corta la empresa afectada, y
 * el login se niega del todo únicamente si no le queda ninguna.
 */
class AccesoSuspendidoTest extends TestCase
{
    private const CLAVE = 'secreto123';

    public function test_con_la_clave_mal_el_mensaje_sigue_siendo_genérico(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa);

        // No debe revelar nada a quien no sabe la contraseña.
        $this->postJson('/api/auth/login', ['email' => $usuario->Email, 'password' => 'incorrecta'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Las credenciales no son válidas.');
    }

    public function test_un_proveedor_al_dia_entra_normal(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa);

        $this->postJson('/api/auth/login', ['email' => $usuario->Email, 'password' => self::CLAVE])
            ->assertOk()
            ->assertJsonStructure(['usuario', 'token']);
    }

    public function test_suspendido_en_su_unica_empresa_no_puede_entrar(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $proveedor->forceFill(['Id_Estado_Proveedor' => EstadoProveedor::SUSPENDIDO])->save();

        $this->postJson('/api/auth/login', ['email' => $usuario->Email, 'password' => self::CLAVE])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', AuthService::MENSAJE_ACCESO_SUSPENDIDO);
    }

    public function test_un_usuario_inactivo_recibe_el_mensaje_de_contactar_al_admin(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa);

        $usuario->forceFill(['Activo' => false])->save();

        $this->postJson('/api/auth/login', ['email' => $usuario->Email, 'password' => self::CLAVE])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', AuthService::MENSAJE_ACCESO_SUSPENDIDO);
    }

    /** El intento bloqueado se distingue del intento con clave incorrecta. */
    public function test_el_bloqueo_queda_registrado_en_la_bitacora(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $proveedor->forceFill(['Id_Estado_Proveedor' => EstadoProveedor::SUSPENDIDO])->save();

        $this->postJson('/api/auth/login', ['email' => $usuario->Email, 'password' => self::CLAVE]);

        $this->assertDatabaseHas('Bitacora_Acceso', [
            'Email_Intento' => $usuario->Email,
            'Tipo_Evento' => 'Login_Bloqueado',
        ]);
    }

    /**
     * Suspendido en UNA empresa pero al día en otra: entra igual, y el
     * middleware le corta solo la empresa suspendida.
     */
    public function test_suspendido_en_una_empresa_sigue_entrando_por_la_otra(): void
    {
        $empresaSuspendida = $this->crearEmpresa();
        $empresaAlDia = $this->crearEmpresa();

        [$usuario, $proveedorSuspendido] = $this->crearProveedorConUsuario($empresaSuspendida);

        // El MISMO usuario también es proveedor de la otra empresa.
        $this->vincularProveedorEnOtraEmpresa($usuario, $empresaAlDia);

        $proveedorSuspendido->forceFill(['Id_Estado_Proveedor' => EstadoProveedor::SUSPENDIDO])->save();

        // El login funciona: le queda una empresa disponible.
        $this->postJson('/api/auth/login', ['email' => $usuario->Email, 'password' => self::CLAVE])->assertOk();

        // La empresa suspendida queda cortada por el middleware...
        $this->getJson('/api/mi-documentos', $this->cabecerasComo($usuario, $empresaSuspendida))
            ->assertForbidden()
            ->assertJsonPath('message', AuthService::MENSAJE_ACCESO_SUSPENDIDO)
            ->assertJsonPath('proveedor_suspendido', true);

        // ...y la que está al día sigue funcionando.
        $this->getJson('/api/mi-documentos', $this->cabecerasComo($usuario, $empresaAlDia))
            ->assertOk();
    }

    public function test_suspendido_en_todas_sus_empresas_no_entra(): void
    {
        $empresaA = $this->crearEmpresa();
        $empresaB = $this->crearEmpresa();

        [$usuario, $proveedorA] = $this->crearProveedorConUsuario($empresaA);
        $proveedorB = $this->vincularProveedorEnOtraEmpresa($usuario, $empresaB);

        $proveedorA->forceFill(['Id_Estado_Proveedor' => EstadoProveedor::SUSPENDIDO])->save();
        $proveedorB->forceFill(['Id_Estado_Proveedor' => EstadoProveedor::SUSPENDIDO])->save();

        $this->postJson('/api/auth/login', ['email' => $usuario->Email, 'password' => self::CLAVE])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', AuthService::MENSAJE_ACCESO_SUSPENDIDO);
    }

    /** Un usuario interno no se ve afectado por esto. */
    public function test_los_usuarios_internos_no_se_bloquean_por_documentos(): void
    {
        $empresa = $this->crearEmpresa();
        $interno = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->postJson('/api/auth/login', ['email' => $interno->Email, 'password' => self::CLAVE])->assertOk();
    }

    /** Suma un Proveedor del MISMO usuario en otra empresa. */
    private function vincularProveedorEnOtraEmpresa($usuario, $empresa)
    {
        \App\Modules\Auth\Models\UsuarioEmpresa::create([
            'Id_Usuario' => $usuario->Id_Usuario,
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Rol' => $this->idRol('Proveedor'),
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        $proveedor = \App\Modules\Proveedores\Models\Proveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Estado_Proveedor' => EstadoProveedor::APROBADO,
            'Ruc' => $this->rucFalso(),
            'Razon_Social' => 'Proveedor Otra Empresa',
            'Email' => $usuario->Email,
            'Seccion_Actual' => 1,
            'Porcentaje_Completado_Ficha' => 0,
            'Fecha_Postulacion' => now(),
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        $usuario->proveedores()->attach($proveedor->Id_Proveedor, [
            'Activo' => true,
            'Fecha_Creacion' => now()->format('Y-m-d\TH:i:s'),
        ]);

        return $proveedor;
    }
}
