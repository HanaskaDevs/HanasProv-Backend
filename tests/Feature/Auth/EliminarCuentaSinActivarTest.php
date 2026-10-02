<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\CodigoActivacion;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioEmpresa;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Borrado DEFINITIVO de cuentas que nunca se activaron (02-oct-2026).
 *
 * Un correo mal escrito al dar de alta deja una cuenta fantasma: nadie va
 * a entrar nunca y el correo queda ocupado, así que no se puede volver a
 * usar el verdadero. Inactivarla no resuelve eso; borrarla, sí.
 *
 * ES IRREVERSIBLE, así que lo que más se protege acá son los LÍMITES: a
 * quién no se puede borrar y quién no puede hacerlo.
 */
class EliminarCuentaSinActivarTest extends TestCase
{
    /** Cuenta recién creada: código enviado, nunca activada. */
    private function cuentaSinActivar($empresa): Usuario
    {
        return $this->crearUsuarioInterno($empresa, 'Compras', [
            'Requiere_Cambio_Password' => true,
            'Ultimo_Acceso' => null,
        ]);
    }

    private function borrar(Usuario $objetivo, Usuario $ejecutor, $empresa)
    {
        return $this->deleteJson(
            "/api/usuarios/{$objetivo->Id_Usuario}",
            [],
            $this->cabecerasComo($ejecutor, $empresa)
        );
    }

    public function test_se_borra_la_cuenta_que_nunca_se_activo(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $fantasma = $this->cuentaSinActivar($empresa);
        $id = $fantasma->Id_Usuario;

        $this->borrar($fantasma, $sistemas, $empresa)->assertOk();

        $this->assertFalse(
            Usuario::where('Id_Usuario', $id)->exists(),
            'La fila tiene que dejar de existir: es borrado definitivo, no baja lógica.'
        );
    }

    /** El correo queda libre: es el motivo de que esto exista. */
    public function test_el_correo_queda_libre_para_volver_a_usarse(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $fantasma = $this->cuentaSinActivar($empresa);
        $correo = $fantasma->Email;

        $this->borrar($fantasma, $sistemas, $empresa)->assertOk();

        $this->assertFalse(Usuario::where('Email', $correo)->exists());
    }

    /** No puede quedar el andamiaje apuntando a un usuario que ya no está. */
    public function test_se_lleva_los_vinculos_de_la_cuenta(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $fantasma = $this->cuentaSinActivar($empresa);
        $id = $fantasma->Id_Usuario;

        CodigoActivacion::create([
            'Email' => $fantasma->Email,
            'Tipo' => 'Bienvenida',
            'Codigo' => '123456',
            'Fecha_Expiracion' => now()->addDays(3),
            'Usado' => false,
            'Fecha_Creacion' => now(),
        ]);

        $this->assertTrue(UsuarioEmpresa::where('Id_Usuario', $id)->exists());

        $this->borrar($fantasma, $sistemas, $empresa)->assertOk();

        $this->assertFalse(UsuarioEmpresa::where('Id_Usuario', $id)->exists());
        $this->assertFalse(
            CodigoActivacion::where('Email', $fantasma->Email)->exists(),
            'Un código vivo apuntando a un correo libre podría activar una cuenta futura.'
        );
    }

    // ---- Los límites ------------------------------------------------

    /** Ya activada: tiene historia, y borrarla deja huecos. */
    public function test_no_se_puede_borrar_una_cuenta_ya_activada(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $activo = $this->crearUsuarioInterno($empresa, 'Compras', [
            'Requiere_Cambio_Password' => false,
        ]);

        $this->borrar($activo, $sistemas, $empresa)->assertStatus(422);

        $this->assertTrue(Usuario::where('Id_Usuario', $activo->Id_Usuario)->exists());
    }

    /**
     * EL CASO SUTIL: Requiere_Cambio_Password vuelve a true cuando se le
     * reinicia la contraseña a alguien, aunque esa persona lleve meses
     * usando el portal. Sin mirar Ultimo_Acceso, esa cuenta se vería como
     * "nunca activada" y se podría borrar con todo su historial.
     */
    public function test_no_se_puede_borrar_a_quien_ya_ingreso_alguna_vez(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $usuario = $this->crearUsuarioInterno($empresa, 'Compras', [
            'Requiere_Cambio_Password' => true,
            'Ultimo_Acceso' => now()->subMonth(),
        ]);

        $respuesta = $this->borrar($usuario, $sistemas, $empresa)->assertStatus(422);

        $this->assertStringContainsString('ya se usó para ingresar', (string) $respuesta->json('message'));
        $this->assertTrue(Usuario::where('Id_Usuario', $usuario->Id_Usuario)->exists());
    }

    /**
     * Si quedó información asociada, se rechaza con un mensaje claro en
     * vez de reventar con un error de clave foránea. Hay 27 tablas
     * apuntando a Usuario y todas son NO ACTION.
     */
    public function test_no_se_puede_borrar_si_quedo_informacion_asociada(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $fantasma = $this->cuentaSinActivar($empresa);

        // Un archivo subido por esa cuenta: no es andamiaje, es contenido.
        DB::table('Archivo')->insert([
            'Nombre_Original' => 'algo.pdf',
            'Ruta_Almacenamiento' => 'pruebas/'.uniqid().'.pdf',
            'Hash_Archivo' => hash('sha256', uniqid('', true)),
            'Tipo_Mime' => 'application/pdf',
            'Tamano_Bytes' => 10,
            'Categoria_Archivo' => 'prueba',
            'Id_Usuario_Carga' => $fantasma->Id_Usuario,
            'Fecha_Carga' => now()->format('Y-m-d\TH:i:s'),
            'Activo' => 1,
        ]);

        $respuesta = $this->borrar($fantasma, $sistemas, $empresa)->assertStatus(422);

        $this->assertStringContainsString('información asociada', (string) $respuesta->json('message'));
        $this->assertTrue(Usuario::where('Id_Usuario', $fantasma->Id_Usuario)->exists());
    }

    public function test_un_rol_que_no_es_sistemas_no_puede_borrar(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        $fantasma = $this->cuentaSinActivar($empresa);

        $this->borrar($fantasma, $admin, $empresa)->assertForbidden();

        $this->assertTrue(Usuario::where('Id_Usuario', $fantasma->Id_Usuario)->exists());
    }

    public function test_nadie_puede_borrarse_a_si_mismo(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas', [
            'Requiere_Cambio_Password' => true,
            'Ultimo_Acceso' => null,
        ]);

        $this->borrar($sistemas, $sistemas, $empresa)->assertStatus(422);

        $this->assertTrue(Usuario::where('Id_Usuario', $sistemas->Id_Usuario)->exists());
    }

    /** El caso real: un proveedor externo al que se le erró el correo. */
    public function test_se_borra_un_proveedor_externo_sin_activar(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $externo = Usuario::create([
            'Email' => 'mal_escrito_'.Str::lower(Str::random(8)).'@test.local',
            'Password_Hash' => bcrypt('x'),
            'Nombre_Completo' => 'Proveedor mal cargado',
            'Tipo_Usuario' => 'Proveedor',
            'Requiere_Cambio_Password' => true,
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        UsuarioEmpresa::create([
            'Id_Usuario' => $externo->Id_Usuario,
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Rol' => $this->idRol('Proveedor'),
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        $this->borrar($externo, $sistemas, $empresa)->assertOk();

        $this->assertFalse(Usuario::where('Id_Usuario', $externo->Id_Usuario)->exists());
        $this->assertSame(0, Proveedor::where('Email', $externo->Email)->count());
    }
}
