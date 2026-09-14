<?php

namespace Tests;

use App\Models\Empresa;
use App\Models\Rol;
use App\Modules\Auth\Models\Sesion;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioEmpresa;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Base de todos los tests.
 *
 * ¡OJO CON EL AISLAMIENTO! Se usa DatabaseTransactions, NO RefreshDatabase.
 * Los tests corren contra la MISMA base de datos del .env (la de pruebas),
 * que tiene datos reales cargados a mano y el esquema ya migrado:
 *
 *  - DatabaseTransactions abre una transacción antes de cada test y hace
 *    rollback al terminar -> lo que el test escribe desaparece, y lo que ya
 *    estaba sigue ahí intacto.
 *  - RefreshDatabase, en cambio, hace `migrate:fresh`: BORRA todas las
 *    tablas y las vuelve a crear. Apuntado a esta base, la primera corrida
 *    se lleva puestos los proveedores, usuarios y documentos existentes.
 *
 * Por eso ningún test debería usar RefreshDatabase mientras la base de
 * pruebas sea compartida con datos que a alguien le importan. Si algún día
 * hace falta correr las migraciones desde cero, eso va contra una base
 * aparte y descartable, no contra esta.
 *
 * Consecuencia práctica del rollback: los catálogos que el sistema NO
 * permite editar por interfaz (Rol, Estado_Proveedor, Tipo_Documento...) se
 * dan por existentes y se leen de la base, no se crean por test. Eso es
 * justamente lo que garantiza el RolEstadoProveedorSeeder.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Id de un Rol por nombre. Si falta, el mensaje apunta directo al
     * seeder en vez de reventar más adelante con un error de foreign key
     * que no dice nada.
     */
    protected function idRol(string $nombreRol): int
    {
        $idRol = Rol::where('Nombre_Rol', $nombreRol)->value('Id_Rol');

        $this->assertNotNull(
            $idRol,
            "No existe el rol '{$nombreRol}' en la base de pruebas. Corré: php artisan db:seed --class=RolEstadoProveedorSeeder"
        );

        return (int) $idRol;
    }

    /** RUC de 13 dígitos único, para no chocar con los que ya existen. */
    protected function rucFalso(): string
    {
        return (string) random_int(1000000000000, 9999999999999);
    }

    protected function crearEmpresa(array $extra = []): Empresa
    {
        return Empresa::create([
            'Razon_Social' => 'Empresa Test '.Str::random(6),
            'Ruc' => $this->rucFalso(),
            'Nombre_Comercial' => 'Test',
            // Empresa_BC hace falta para cualquier cosa que consulte las
            // tablas espejo BC_* (pedidos por bodega, fill rate): sin él,
            // PedidoInternoService lanza "Esta empresa no tiene configurado
            // el código Empresa_BC" y el test falla por el setup, no por lo
            // que estaba probando.
            'Empresa_BC' => 'test',
            'Activo' => true,
            'Fecha_Creacion' => now(),
            ...$extra,
        ]);
    }

    /**
     * Usuario interno (staff) con un rol dado DENTRO de esa empresa -> el
     * rol nunca es un campo del usuario, siempre vive en el pivote
     * Usuario_Empresa (el mismo usuario puede tener roles distintos en
     * empresas distintas).
     */
    protected function crearUsuarioInterno(Empresa $empresa, string $rol = 'Sistemas', array $extra = []): Usuario
    {
        $usuario = Usuario::create([
            'Email' => 'interno_'.Str::lower(Str::random(10)).'@test.local',
            'Password_Hash' => Hash::make('secreto123'),
            'Nombre_Completo' => 'Interno de Prueba',
            'Cargo' => 'QA',
            'Tipo_Usuario' => 'Interno',
            'Requiere_Cambio_Password' => false,
            'Activo' => true,
            'Fecha_Creacion' => now(),
            ...$extra,
        ]);

        UsuarioEmpresa::create([
            'Id_Usuario' => $usuario->Id_Usuario,
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Rol' => $this->idRol($rol),
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        return $usuario->fresh();
    }

    /**
     * Usuario externo + su Proveedor en esa empresa, ya vinculados por
     * Usuario_Proveedor (así queda igual que después de activar la cuenta).
     *
     * @return array{0: Usuario, 1: Proveedor}
     */
    protected function crearProveedorConUsuario(Empresa $empresa, array $extraProveedor = []): array
    {
        $email = 'prov_'.Str::lower(Str::random(10)).'@test.local';

        $usuario = Usuario::create([
            'Email' => $email,
            'Password_Hash' => Hash::make('secreto123'),
            'Nombre_Completo' => 'Proveedor de Prueba',
            'Tipo_Usuario' => 'Proveedor',
            'Requiere_Cambio_Password' => false,
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        UsuarioEmpresa::create([
            'Id_Usuario' => $usuario->Id_Usuario,
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Rol' => $this->idRol('Proveedor'),
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);

        $proveedor = Proveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Estado_Proveedor' => EstadoProveedor::ASPIRANTE,
            'Ruc' => $this->rucFalso(),
            'Razon_Social' => 'Proveedor Test '.Str::random(6),
            'Email' => $email,
            'Seccion_Actual' => 1,
            'Porcentaje_Completado_Ficha' => 0,
            'Fecha_Postulacion' => now(),
            'Activo' => true,
            'Fecha_Creacion' => now(),
            ...$extraProveedor,
        ]);

        $usuario->proveedores()->attach($proveedor->Id_Proveedor, [
            'Activo' => true,
            'Fecha_Creacion' => now()->format('Y-m-d\TH:i:s'),
        ]);

        return [$usuario->fresh(), $proveedor->fresh()];
    }

    /**
     * Cabeceras para pegarle a la API como este usuario, replicando lo que
     * hace AuthService::login: token de Sanctum + su fila en Sesion (el
     * middleware EmpresaActiva rechaza con 401 si no encuentra una sesión
     * activa para ese token) + el header X-Empresa-Activa que manda el
     * front en cada petición.
     *
     * @return array<string, string>
     */
    protected function cabecerasComo(Usuario $usuario, ?Empresa $empresa = null): array
    {
        $tokenResult = $usuario->createToken('test');

        Sesion::create([
            'Id_Usuario' => $usuario->Id_Usuario,
            'Token' => (string) $tokenResult->accessToken->id,
            'Fecha_Inicio' => now(),
            'Fecha_Expiracion' => now()->addHours(12),
            'Activa' => true,
            'Id_Empresa_Activa' => $empresa?->Id_Empresa,
        ]);

        return array_filter([
            'Authorization' => 'Bearer '.$tokenResult->plainTextToken,
            'Accept' => 'application/json',
            'X-Empresa-Activa' => $empresa ? (string) $empresa->Id_Empresa : null,
        ]);
    }
}
