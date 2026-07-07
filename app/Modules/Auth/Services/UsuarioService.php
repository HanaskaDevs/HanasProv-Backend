<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioEmpresa;
use App\Modules\Auth\Notifications\ClaveTemporalNotification;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UsuarioService
{
    /**
     * Crea un usuario interno (staff) con un rol específico dentro de una empresa.
     * Solo un usuario con rol "Sistemas" en esa empresa puede hacerlo.
     */
    public function crearUsuarioInterno(array $data, Usuario $creador): Usuario
    {
        if (! $creador->esSistemas($data['id_empresa'])) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden crear usuarios internos.');
        }

        return DB::transaction(function () use ($data, $creador) {
            [$usuario, $claveTemporal] = $this->crearUsuarioBase([
                'Email' => $data['email'],
                'Nombre_Completo' => $data['nombre_completo'],
                'Cargo' => $data['cargo'] ?? null,
                'Telefono' => $data['telefono'] ?? null,
                'Tipo_Usuario' => 'Interno',
            ], $creador);

            UsuarioEmpresa::create([
                'Id_Usuario' => $usuario->Id_Usuario,
                'Id_Empresa' => $data['id_empresa'],
                'Id_Rol' => $data['id_rol'],
                'Activo' => true,
                'Creado_Por' => $creador->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);

            $usuario->notify(new ClaveTemporalNotification($claveTemporal));

            return $usuario;
        });
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
            [$usuario, $claveTemporal] = $this->crearUsuarioBase([
                'Email' => $data['email'],
                'Nombre_Completo' => $data['nombre_completo'],
                'Id_Proveedor' => $proveedor->Id_Proveedor,
                'Tipo_Usuario' => 'Proveedor',
            ], $creador);

            $usuario->notify(new ClaveTemporalNotification($claveTemporal));

            return $usuario;
        });
    }

    protected function crearUsuarioBase(array $datos, Usuario $creador): array
    {
        if (Usuario::where('Email', $datos['Email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Ya existe un usuario registrado con este correo.'],
            ]);
        }

        $claveTemporal = $this->generarClaveTemporal();

        $usuario = Usuario::create([
            ...$datos,
            'Password_Hash' => Hash::make($claveTemporal),
            'Requiere_Cambio_Password' => true,
            'Activo' => true,
            'Creado_Por' => $creador->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);

        return [$usuario, $claveTemporal];
    }

    /**
     * Flujo "olvidé mi contraseña". Reglas explícitas del negocio:
     * - Email no existe -> error "el usuario no existe".
     * - Usuario existe pero inactivo -> no se envía nada, error de inactivo.
     * - Usuario existe y activo -> nueva clave temporal por correo.
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

        $claveTemporal = $this->generarClaveTemporal();

        $usuario->forceFill([
            'Password_Hash' => Hash::make($claveTemporal),
            'Requiere_Cambio_Password' => true,
        ])->save();

        // Invalida cualquier sesión/token activo: debe volver a loguearse con la clave temporal
        $usuario->tokens()->delete();

        $usuario->notify(new ClaveTemporalNotification($claveTemporal, esReset: true));
    }

    /**
     * El usuario define su contraseña definitiva tras usar la clave temporal.
     * Se cierra el token actual a propósito: debe iniciar sesión de nuevo.
     */
    public function cambiarPasswordInicial(Usuario $usuario, string $passwordNueva): void
    {
        $usuario->forceFill([
            'Password_Hash' => Hash::make($passwordNueva),
            'Requiere_Cambio_Password' => false,
        ])->save();

        $usuario->tokens()->delete();
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

        $usuario->tokens()->delete();
    }

    protected function generarClaveTemporal(): string
    {
        return Str::upper(Str::random(4)) . '-' . random_int(1000, 9999);
    }
}
