<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\CodigoActivacion;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioEmpresa;
use App\Modules\Auth\Notifications\CodigoActivacionNotification;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UsuarioService
{
    protected const MINUTOS_VIGENCIA_CODIGO = 20;

    /**
     * Crea un usuario interno (staff) dentro de la empresa activa de quien lo crea.
     * Formulario mínimo: solo email + rol. El propio usuario completa
     * Nombre_Completo/Cargo/Telefono al activar su cuenta con el código.
     * Solo un usuario con rol "Sistemas" en esa empresa puede hacerlo.
     */
    public function crearUsuarioInterno(array $data, Usuario $creador, int $idEmpresaActiva): Usuario
    {
        if (! $creador->esSistemas($idEmpresaActiva)) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden crear usuarios internos.');
        }

        return DB::transaction(function () use ($data, $creador, $idEmpresaActiva) {
            $usuario = $this->crearUsuarioBase([
                'Email' => $data['email'],
                // Placeholder obligatorio (la columna es NOT NULL): el usuario
                // real lo define al activar su cuenta.
                'Nombre_Completo' => $data['email'],
                'Tipo_Usuario' => 'Interno',
            ], $creador);

            UsuarioEmpresa::create([
                'Id_Usuario' => $usuario->Id_Usuario,
                'Id_Empresa' => $idEmpresaActiva,
                'Id_Rol' => $data['id_rol'],
                'Activo' => true,
                'Creado_Por' => $creador->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);

            $this->generarYEnviarCodigo($usuario, tipo: 'Bienvenida', creadoPor: $creador->Id_Usuario);

            return $usuario;
        });
    }

    /**
     * Lista los usuarios internos (Tipo_Usuario = Interno) vinculados a la
     * empresa activa, con su rol dentro de esa empresa ya cargado.
     */
    public function listarInternos(int $idEmpresa): Collection
    {
        return Usuario::where('Tipo_Usuario', 'Interno')
            ->whereHas('usuarioEmpresas', fn ($q) => $q->where('Id_Empresa', $idEmpresa))
            ->with(['usuarioEmpresas' => fn ($q) => $q->where('Id_Empresa', $idEmpresa)->with('rol')])
            ->orderBy('Nombre_Completo')
            ->get();
    }

    /**
     * Crea un usuario tipo Proveedor, vinculado a un registro de Proveedor existente.
     * Permitido para rol "Sistemas" o "Administrador" dentro de la empresa dueña del proveedor.
     */
    public function crearUsuarioProveedor(array $data, Usuario $creador): Usuario
    {
        $proveedor = Proveedor::findOrFail($data['id_proveedor']);

        if (! $creador->esSistemas($proveedor->Id_Empresa) && ! $creador->esAdministrador($proveedor->Id_Empresa)) {
            throw new AccessDeniedHttpException('No tiene permisos para crear credenciales de proveedores.');
        }

        return DB::transaction(function () use ($data, $creador, $proveedor) {
            $usuario = $this->crearUsuarioBase([
                'Email' => $data['email'],
                'Nombre_Completo' => $data['email'],
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Tipo_Usuario' => 'Proveedor',
            ], $creador);

            $this->generarYEnviarCodigo(
                $usuario,
                tipo: 'Bienvenida',
                idProveedor: $proveedor->Id_Proveedor,
                creadoPor: $creador->Id_Usuario,
            );

            return $usuario;
        });
    }

    /**
     * Crea el registro base del usuario con un Password_Hash NO utilizable
     * (nadie conoce este valor). El usuario solo obtiene una contraseña real
     * al consumir el código de activación en activarCuenta().
     */
    protected function crearUsuarioBase(array $datos, Usuario $creador): Usuario
    {
        if (Usuario::where('Email', $datos['Email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Ya existe un usuario registrado con este correo.'],
            ]);
        }

        return Usuario::create([
            ...$datos,
            'Password_Hash' => Hash::make(Str::random(40)),
            'Requiere_Cambio_Password' => true,
            'Activo' => true,
            'Creado_Por' => $creador->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);
    }

    /**
     * Genera un código de activación de 20 minutos para el email dado,
     * invalidando cualquier código previo sin usar, y lo envía por correo.
     */
    protected function generarYEnviarCodigo(
        Usuario $usuario,
        string $tipo,
        ?int $idProveedor = null,
        ?int $creadoPor = null,
    ): CodigoActivacion {
        CodigoActivacion::where('Email', $usuario->Email)
            ->where('Usado', false)
            ->update(['Usado' => true, 'Fecha_Uso' => now()]);

        $codigo = $this->generarCodigo();

        $codigoActivacion = CodigoActivacion::create([
            'Email' => $usuario->Email,
            'Id_Proveedor' => $idProveedor,
            'Tipo' => $tipo,
            'Codigo' => $codigo,
            'Fecha_Expiracion' => now()->addMinutes(self::MINUTOS_VIGENCIA_CODIGO),
            'Usado' => false,
            'Creado_Por' => $creadoPor,
            'Fecha_Creacion' => now(),
        ]);

        $usuario->notify(new CodigoActivacionNotification($codigo, esReset: $tipo === 'Reset'));

        return $codigoActivacion;
    }

    /**
     * Flujo "olvidé mi contraseña". Reglas explícitas del negocio:
     * - Email no existe -> error "el usuario no existe".
     * - Usuario existe pero inactivo -> no se envía nada, error de inactivo.
     * - Usuario existe y activo -> nuevo código de activación (tipo Reset) por correo.
     */
    public function olvidePassword(string $email): void
    {
        $usuario = Usuario::where('Email', $email)->first();

        if (! $usuario) {
            throw ValidationException::withMessages([
                'email' => ['El usuario no existe.'],
            ]);
        }

        if (! $usuario->Activo) {
            throw ValidationException::withMessages([
                'email' => ['El usuario se encuentra inactivo. Contacte al administrador.'],
            ]);
        }

        $this->generarYEnviarCodigo($usuario, tipo: 'Reset');

        $this->cerrarSesionesYTokens($usuario);
    }

    /**
     * Consume un código de activación/reset y define la contraseña real del usuario.
     * Usado tanto para la primera activación de cuenta como para "olvidé mi contraseña".
     * No requiere autenticación: el código + email son la prueba de identidad.
     *
     * $datosPerfil (nombre_completo, cargo, telefono) solo se aplican y son
     * obligatorios cuando el código es de tipo "Bienvenida" (primera activación).
     * En un "Reset" de contraseña, esos datos ya existen y se ignoran.
     */
    public function activarCuenta(string $email, string $codigo, string $passwordNueva, array $datosPerfil = []): Usuario
    {
        $usuario = Usuario::where('Email', $email)->first();

        if (! $usuario) {
            throw ValidationException::withMessages([
                'email' => ['El usuario no existe.'],
            ]);
        }

        $codigoActivacion = CodigoActivacion::where('Email', $email)
            ->where('Codigo', $codigo)
            ->where('Usado', false)
            ->orderByDesc('Fecha_Creacion')
            ->first();

        if (! $codigoActivacion) {
            throw ValidationException::withMessages([
                'codigo' => ['El código no es válido o ya fue utilizado.'],
            ]);
        }

        if ($codigoActivacion->Fecha_Expiracion->isPast()) {
            throw ValidationException::withMessages([
                'codigo' => ['El código expiró. Solicita uno nuevo.'],
            ]);
        }

        $esPrimeraActivacion = $codigoActivacion->Tipo === 'Bienvenida';

        if ($esPrimeraActivacion && empty($datosPerfil['nombre_completo'])) {
            throw ValidationException::withMessages([
                'nombre_completo' => ['El nombre completo es requerido para activar la cuenta.'],
            ]);
        }

        return DB::transaction(function () use ($usuario, $codigoActivacion, $passwordNueva, $datosPerfil, $esPrimeraActivacion) {
            $usuario->forceFill([
                'Password_Hash' => Hash::make($passwordNueva),
                'Requiere_Cambio_Password' => false,
                ...($esPrimeraActivacion ? [
                    'Nombre_Completo' => $datosPerfil['nombre_completo'],
                    'Cargo' => $datosPerfil['cargo'] ?? null,
                    'Telefono' => $datosPerfil['telefono'] ?? null,
                ] : []),
            ])->save();

            $codigoActivacion->forceFill([
                'Usado' => true,
                'Fecha_Uso' => now(),
            ])->save();

            return $usuario;
        });
    }

    /**
     * Cambio voluntario de contraseña para un usuario ya autenticado
     * (no relacionado a códigos de activación ni "olvidé mi contraseña").
     * Requiere conocer la contraseña actual.
     */
    public function cambiarPassword(Usuario $usuario, string $passwordActual, string $passwordNueva): void
    {
        if (! Hash::check($passwordActual, $usuario->Password_Hash)) {
            throw ValidationException::withMessages([
                'password_actual' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $usuario->forceFill([
            'Password_Hash' => Hash::make($passwordNueva),
        ])->save();

        $tokenActualId = $usuario->currentAccessToken()?->id;

        $usuario->sesiones()
            ->where('Activa', true)
            ->when($tokenActualId, fn ($q) => $q->where('Token', '!=', (string) $tokenActualId))
            ->update(['Activa' => false]);

        $usuario->tokens()
            ->when($tokenActualId, fn ($q) => $q->where('id', '!=', $tokenActualId))
            ->delete();
    }

    /**
     * Solo rol "Sistemas" puede inactivar usuarios.
     */
    public function inactivar(Usuario $usuario, Usuario $ejecutor, int $idEmpresa): void
    {
        if (! $ejecutor->esSistemas($idEmpresa)) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden inactivar usuarios.');
        }

        $usuario->forceFill([
            'Activo' => false,
            'Modificado_Por' => $ejecutor->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        $this->cerrarSesionesYTokens($usuario);
    }

    public function cerrarSesionesYTokens(Usuario $usuario): void
    {
        $usuario->sesiones()->where('Activa', true)->update(['Activa' => false]);
        $usuario->tokens()->delete();
    }

    protected function generarCodigo(): string
    {
        return Str::upper(Str::random(4)) . '-' . random_int(1000, 9999);
    }
}
