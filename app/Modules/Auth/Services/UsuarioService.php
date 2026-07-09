<?php

namespace App\Modules\Auth\Services;

use App\Models\Rol;
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
     * Solo rol "Sistemas" puede ver este panel.
     */
    public function listarInternos(int $idEmpresa, Usuario $solicitante): Collection
    {
        $this->verificarAccesoPanelInternos($solicitante, $idEmpresa);

        return Usuario::where('Tipo_Usuario', 'Interno')
            ->whereHas('usuarioEmpresas', fn ($q) => $q->where('Id_Empresa', $idEmpresa))
            ->with(['usuarioEmpresas' => fn ($q) => $q->where('Id_Empresa', $idEmpresa)->with('rol')])
            ->orderBy('Nombre_Completo')
            ->get();
    }

    public function verificarAccesoPanelInternos(Usuario $solicitante, int $idEmpresa): void
    {
        if (! $solicitante->esSistemas($idEmpresa)) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden ver el panel de usuarios internos.');
        }
    }

    /**
     * Crea un usuario externo (Proveedor) dentro de la empresa activa de quien lo crea.
     * Formulario mínimo: solo email. El Proveedor (la empresa proveedora en sí)
     * todavía NO existe en este punto -> Usuario.Id_Proveedor queda NULL hasta
     * que el usuario complete la Ficha de Proveedor tras activar su cuenta.
     * Permitido para rol "Sistemas" o "Admin" dentro de la empresa activa.
     */
    public function crearUsuarioProveedor(array $data, Usuario $creador, int $idEmpresaActiva): Usuario
    {
        if (! $creador->esSistemas($idEmpresaActiva) && ! $creador->esAdmin($idEmpresaActiva)) {
            throw new AccessDeniedHttpException('No tiene permisos para crear usuarios externos.');
        }

        $idRolProveedor = Rol::where('Nombre_Rol', 'Proveedor')->value('Id_Rol');

        if (! $idRolProveedor) {
            throw ValidationException::withMessages([
                'email' => ['No existe el rol "Proveedor" configurado en el sistema.'],
            ]);
        }

        return DB::transaction(function () use ($data, $creador, $idEmpresaActiva, $idRolProveedor) {
            $usuario = $this->crearUsuarioBase([
                'Email' => $data['email'],
                'Nombre_Completo' => $data['email'],
                'Tipo_Usuario' => 'Proveedor',
            ], $creador);

            UsuarioEmpresa::create([
                'Id_Usuario' => $usuario->Id_Usuario,
                'Id_Empresa' => $idEmpresaActiva,
                'Id_Rol' => $idRolProveedor,
                'Activo' => true,
                'Creado_Por' => $creador->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);

            $this->generarYEnviarCodigo($usuario, tipo: 'Bienvenida', creadoPor: $creador->Id_Usuario);

            return $usuario;
        });
    }

    /**
     * Lista los usuarios externos (Tipo_Usuario = Proveedor) vinculados a la
     * empresa activa, con su rol dentro de esa empresa ya cargado.
     * Solo rol "Sistemas" o "Admin" pueden ver este panel.
     */
    public function listarExternos(int $idEmpresa, Usuario $solicitante): Collection
    {
        $this->verificarAccesoPanelExternos($solicitante, $idEmpresa);

        return Usuario::where('Tipo_Usuario', 'Proveedor')
            ->whereHas('usuarioEmpresas', fn ($q) => $q->where('Id_Empresa', $idEmpresa))
            ->with(['usuarioEmpresas' => fn ($q) => $q->where('Id_Empresa', $idEmpresa)->with('rol'), 'proveedor'])
            ->orderBy('Nombre_Completo')
            ->get();
    }

    public function verificarAccesoPanelExternos(Usuario $solicitante, int $idEmpresa): void
    {
        if (! $solicitante->esSistemas($idEmpresa) && ! $solicitante->esAdmin($idEmpresa)) {
            throw new AccessDeniedHttpException('No tiene permisos para ver el panel de usuarios externos.');
        }
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

        // "Primera activación" se determina por el ESTADO REAL del usuario
        // (nunca completó ninguna activación todavía), NO por el tipo de
        // código usado para lograrlo. Así, si el código de bienvenida
        // expiró y se reenvía (o se usa "olvidé mi contraseña" como
        // reintento), sigue pidiendo nombre completo y creando el
        // Proveedor cascarón la primera vez que de verdad se complete.
        $esPrimeraActivacion = (bool) $usuario->Requiere_Cambio_Password;

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

            // Primera activación de un usuario externo: se crea el "cascarón"
            // del Proveedor para que pueda empezar a llenar su Ficha.
            if ($esPrimeraActivacion && $usuario->Tipo_Usuario === 'Proveedor' && ! $usuario->Id_Proveedor) {
                $idEmpresa = $usuario->usuarioEmpresas()->where('Activo', true)->value('Id_Empresa');

                // Si un intento de activación anterior (con un código que
                // luego expiró) ya alcanzó a crear el cascarón pero no
                // llegó a vincularlo al usuario, lo reutilizamos en vez de
                // intentar crear uno nuevo (chocaría con la restricción
                // UNIQUE de Id_Empresa+Ruc, ya que Ruc sigue en NULL).
                $proveedor = Proveedor::where('Id_Empresa', $idEmpresa)
                    ->where('Email', $usuario->Email)
                    ->whereNull('Ruc')
                    ->first();

                if (! $proveedor) {
                    $proveedor = Proveedor::create([
                        'Id_Empresa' => $idEmpresa,
                        'Email' => $usuario->Email,
                        'Seccion_Actual' => 1,
                        'Porcentaje_Completado_Ficha' => 0,
                        'Fecha_Postulacion' => now(),
                        'Activo' => true,
                        'Fecha_Creacion' => now(),
                    ]);
                }

                $usuario->forceFill(['Id_Proveedor' => $proveedor->Id_Proveedor])->save();
            }

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
    /**
     * Reenvía un código de activación a un usuario que todavía no ha
     * completado su primera activación (Requiere_Cambio_Password = true).
     * Los mismos permisos que crear ese tipo de usuario: internos ->
     * solo Sistemas; externos -> Sistemas o Admin.
     */
    public function reenviarCodigoActivacion(Usuario $usuario, Usuario $solicitante, int $idEmpresa): void
    {
        if (! $usuario->Requiere_Cambio_Password) {
            throw ValidationException::withMessages([
                'email' => ['Este usuario ya activó su cuenta. Si olvidó su contraseña, use la opción "Olvidé mi contraseña".'],
            ]);
        }

        $puedeGestionar = $usuario->Tipo_Usuario === 'Interno'
            ? $solicitante->esSistemas($idEmpresa)
            : ($solicitante->esSistemas($idEmpresa) || $solicitante->esAdmin($idEmpresa));

        if (! $puedeGestionar) {
            throw new AccessDeniedHttpException('No tiene permisos para reenviar el código a este usuario.');
        }

        $this->generarYEnviarCodigo(
            $usuario,
            tipo: 'Bienvenida',
            idProveedor: $usuario->Id_Proveedor,
            creadoPor: $solicitante->Id_Usuario,
        );
    }

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