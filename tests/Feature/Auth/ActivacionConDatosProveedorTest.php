<?php

namespace Tests\Feature\Auth;

use App\Models\Rol;
use App\Modules\Auth\Models\CodigoActivacion;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioEmpresa;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Al activar la cuenta, a un proveedor se le piden RUC y Razón Social.
 *
 * Antes la ficha se creaba vacía y el proveedor aparecía en los listados sin
 * nombre ni RUC: imposible de identificar para quien lo tenía que revisar, y
 * de hecho reventaba la pantalla de Proveedores.
 */
class ActivacionConDatosProveedorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();          // el endpoint tiene throttle
        Notification::fake();
    }

    /** Usuario externo SIN Proveedor todavía, tal como queda recién creado. */
    private function crearUsuarioProveedorSinFicha($empresa): Usuario
    {
        $usuario = Usuario::create([
            'Email' => 'prov_'.Str::lower(Str::random(10)).'@test.local',
            'Password_Hash' => Hash::make(Str::random(40)),
            'Nombre_Completo' => 'pendiente',
            'Tipo_Usuario' => 'Proveedor',
            'Requiere_Cambio_Password' => true,
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        UsuarioEmpresa::create([
            'Id_Usuario' => $usuario->Id_Usuario,
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Rol' => Rol::where('Nombre_Rol', 'Proveedor')->value('Id_Rol'),
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        return $usuario->fresh();
    }

    private function crearCodigo(Usuario $usuario): string
    {
        $codigo = 'TEST-'.random_int(1000, 9999);

        CodigoActivacion::create([
            'Email' => $usuario->Email,
            'Tipo' => 'Bienvenida',
            'Codigo' => $codigo,
            'Fecha_Expiracion' => now()->addMinutes(20),
            'Usado' => false,
            'Fecha_Creacion' => now(),
        ]);

        return $codigo;
    }

    private function datosBase(Usuario $usuario, string $codigo): array
    {
        return [
            'email' => $usuario->Email,
            'codigo' => $codigo,
            'password_nueva' => 'Segura#2026',
            'password_nueva_confirmation' => 'Segura#2026',
            'nombre_completo' => 'Juan Pérez',
            'cargo' => 'Gerente',
            'telefono' => '0999999999',
        ];
    }

    // ---------------------------------------------------------------
    // Paso 1: validar el código
    // ---------------------------------------------------------------

    public function test_a_un_proveedor_nuevo_se_le_piden_los_datos_de_empresa(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioProveedorSinFicha($empresa);
        $codigo = $this->crearCodigo($usuario);

        $this->postJson('/api/auth/validar-codigo', ['email' => $usuario->Email, 'codigo' => $codigo])
            ->assertOk()
            ->assertJson(['requiere_datos_proveedor' => true]);
    }

    public function test_a_un_usuario_interno_no_se_le_piden(): void
    {
        $empresa = $this->crearEmpresa();
        $interno = $this->crearUsuarioInterno($empresa, 'Compras');
        $interno->forceFill(['Requiere_Cambio_Password' => true])->save();
        $codigo = $this->crearCodigo($interno);

        $this->postJson('/api/auth/validar-codigo', ['email' => $interno->Email, 'codigo' => $codigo])
            ->assertOk()
            ->assertJson(['requiere_datos_proveedor' => false]);
    }

    public function test_un_codigo_invalido_se_detecta_en_el_paso_1(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioProveedorSinFicha($empresa);
        $this->crearCodigo($usuario);

        $this->postJson('/api/auth/validar-codigo', ['email' => $usuario->Email, 'codigo' => 'XXXX-0000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    public function test_el_paso_1_no_delata_si_el_correo_existe(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioProveedorSinFicha($empresa);
        $this->crearCodigo($usuario);

        $conCuenta = $this->postJson('/api/auth/validar-codigo', ['email' => $usuario->Email, 'codigo' => 'XXXX-0000']);
        $sinCuenta = $this->postJson('/api/auth/validar-codigo', ['email' => 'nadie_zzz@nada.local', 'codigo' => 'XXXX-0000']);

        $this->assertSame($conCuenta->json('errors'), $sinCuenta->json('errors'));
    }

    // ---------------------------------------------------------------
    // Activación
    // ---------------------------------------------------------------

    public function test_la_ficha_se_crea_con_ruc_y_razon_social(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioProveedorSinFicha($empresa);
        $codigo = $this->crearCodigo($usuario);
        $ruc = $this->rucFalso();

        $this->postJson('/api/auth/activar-cuenta', [
            ...$this->datosBase($usuario, $codigo),
            'ruc' => $ruc,
            'razon_social' => 'DISTRIBUIDORA DEL VALLE S.A.',
        ])->assertOk();

        $proveedor = $usuario->fresh()->proveedores()->first();

        $this->assertNotNull($proveedor, 'Debería haberse creado la ficha del proveedor.');
        $this->assertSame($ruc, $proveedor->Ruc);
        $this->assertSame('DISTRIBUIDORA DEL VALLE S.A.', $proveedor->Razon_Social);
        $this->assertSame($empresa->Id_Empresa, (int) $proveedor->Id_Empresa);
    }

    public function test_sin_ruc_no_deja_activar(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioProveedorSinFicha($empresa);
        $codigo = $this->crearCodigo($usuario);

        $this->postJson('/api/auth/activar-cuenta', [
            ...$this->datosBase($usuario, $codigo),
            'razon_social' => 'SIN RUC S.A.',
        ])->assertStatus(422)->assertJsonValidationErrors('ruc');

        // Y no debe quedar nada a medias.
        $this->assertSame(0, $usuario->fresh()->proveedores()->count());
    }

    public function test_sin_razon_social_no_deja_activar(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioProveedorSinFicha($empresa);
        $codigo = $this->crearCodigo($usuario);

        $this->postJson('/api/auth/activar-cuenta', [
            ...$this->datosBase($usuario, $codigo),
            'ruc' => $this->rucFalso(),
        ])->assertStatus(422)->assertJsonValidationErrors('razon_social');
    }

    public function test_un_ruc_de_13_digitos_es_obligatorio(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioProveedorSinFicha($empresa);
        $codigo = $this->crearCodigo($usuario);

        $this->postJson('/api/auth/activar-cuenta', [
            ...$this->datosBase($usuario, $codigo),
            'ruc' => '123',
            'razon_social' => 'CORTA S.A.',
        ])->assertStatus(422)->assertJsonValidationErrors('ruc');
    }

    public function test_rechaza_un_ruc_ya_registrado_en_esa_empresa(): void
    {
        $empresa = $this->crearEmpresa();
        [, $existente] = $this->crearProveedorConUsuario($empresa);

        $usuario = $this->crearUsuarioProveedorSinFicha($empresa);
        $codigo = $this->crearCodigo($usuario);

        $respuesta = $this->postJson('/api/auth/activar-cuenta', [
            ...$this->datosBase($usuario, $codigo),
            'ruc' => $existente->Ruc,
            'razon_social' => 'INTENTO DUPLICADO S.A.',
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('ruc');
        $this->assertStringContainsString('Ya existe', $respuesta->json('errors.ruc.0'));

        // La cuenta NO debe quedar activada a medias.
        $this->assertTrue((bool) $usuario->fresh()->Requiere_Cambio_Password);
        $this->assertSame(0, $usuario->fresh()->proveedores()->count());
    }

    public function test_un_interno_activa_sin_ruc_ni_razon_social(): void
    {
        $empresa = $this->crearEmpresa();
        $interno = $this->crearUsuarioInterno($empresa, 'Compras');
        $interno->forceFill(['Requiere_Cambio_Password' => true])->save();
        $codigo = $this->crearCodigo($interno);

        $this->postJson('/api/auth/activar-cuenta', $this->datosBase($interno, $codigo))->assertOk();

        $this->assertFalse((bool) $interno->fresh()->Requiere_Cambio_Password);
        $this->assertSame(0, Proveedor::where('Email', $interno->Email)->count());
    }
}
