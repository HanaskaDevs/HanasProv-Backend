<?php

namespace App\Modules\Auth\Services;

use App\Models\Rol;
use App\Modules\Auth\Models\CodigoActivacion;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioBodega;
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
     * Crea un usuario interno (staff) en una o varias empresas donde quien
     * lo crea tenga rol "Sistemas". El propio usuario completa
     * Nombre_Completo/Cargo/Telefono al activar su cuenta con el código.
     */
    public function crearUsuarioInterno(array $data, Usuario $creador): Usuario
    {
        if (! $creador->esSistemasGlobal()) {
    throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden crear usuarios internos.');
}
$idRolProveedor = Rol::where('Nombre_Rol', 'Proveedor')->value('Id_Rol');

if ((int) $data['id_rol'] === (int) $idRolProveedor) {
    throw ValidationException::withMessages([
        'id_rol' => ['El rol Proveedor no puede asignarse a un usuario interno.'],
    ]);
}

        return DB::transaction(function () use ($data, $creador) {
            $usuario = $this->crearUsuarioBase([
                'Email' => $data['email'],
                'Nombre_Completo' => $data['email'],
                'Tipo_Usuario' => 'Interno',
            ], $creador);

            foreach ($data['id_empresas'] as $idEmpresa) {
                UsuarioEmpresa::create([
                    'Id_Usuario' => $usuario->Id_Usuario,
                    'Id_Empresa' => $idEmpresa,
                    'Id_Rol' => $data['id_rol'],
                    'Activo' => true,
                    'Creado_Por' => $creador->Id_Usuario,
                    'Fecha_Creacion' => now(),
                ]);
            }

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
     * Crea un usuario externo (Proveedor) en una o varias empresas.
     * El Proveedor (la empresa proveedora en sí) todavía NO existe en este
     * punto -> se crea uno por cada empresa recién cuando el usuario activa
     * su cuenta por primera vez.
     * Permitido para rol "Sistemas" o "Admin" en cada empresa seleccionada.
     */
    public function crearUsuarioProveedor(array $data, Usuario $creador): Usuario
    {
        $idRolProveedor = Rol::where('Nombre_Rol', 'Proveedor')->value('Id_Rol');

        if (! $idRolProveedor) {
            throw ValidationException::withMessages([
                'email' => ['No existe el rol "Proveedor" configurado en el sistema.'],
            ]);
        }

        foreach ($data['id_empresas'] as $idEmpresa) {
            if (! $creador->esSistemas($idEmpresa) && ! $creador->esAdmin($idEmpresa)) {
                throw new AccessDeniedHttpException('No tiene permisos para crear proveedores en una de las empresas seleccionadas.');
            }
        }

        return DB::transaction(function () use ($data, $creador, $idRolProveedor) {
            $usuario = $this->crearUsuarioBase([
                'Email' => $data['email'],
                'Nombre_Completo' => $data['email'],
                'Tipo_Usuario' => 'Proveedor',
            ], $creador);

            foreach ($data['id_empresas'] as $idEmpresa) {
                UsuarioEmpresa::create([
                    'Id_Usuario' => $usuario->Id_Usuario,
                    'Id_Empresa' => $idEmpresa,
                    'Id_Rol' => $idRolProveedor,
                    'Activo' => true,
                    'Creado_Por' => $creador->Id_Usuario,
                    'Fecha_Creacion' => now(),
                ]);
            }

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
            ->with([
    'usuarioEmpresas' => fn ($q) => $q->where('Id_Empresa', $idEmpresa)->with('rol'),
    'proveedores' => fn ($q) => $q->where('Id_Empresa', $idEmpresa),
])
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
        ?int $creadoPor = null,
    ): CodigoActivacion {
        CodigoActivacion::where('Email', $usuario->Email)
            ->where('Usado', false)
            ->update(['Usado' => true, 'Fecha_Uso' => now()->format('Y-m-d\TH:i:s')]);

        $codigo = $this->generarCodigo();

        $codigoActivacion = CodigoActivacion::create([
            'Email' => $usuario->Email,
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
        // código usado para lograrlo.
        $esPrimeraActivacion = (bool) $usuario->Requiere_Cambio_Password;

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

            // Primera activación de un usuario externo: se crea un "cascarón"
            // de Proveedor por CADA empresa a la que tiene acceso, para que
            // pueda empezar a llenar su Ficha en cada una por separado.
            if ($esPrimeraActivacion && $usuario->Tipo_Usuario === 'Proveedor' && $usuario->proveedores()->count() === 0) {
                $idsEmpresas = $usuario->usuarioEmpresas()->where('Activo', true)->pluck('Id_Empresa');

                foreach ($idsEmpresas as $idEmpresa) {
                    // Si un intento de activación anterior (con un código que
                    // luego expiró) ya alcanzó a crear el cascarón pero no
                    // llegó a vincularlo al usuario, lo reutilizamos en vez de
                    // crear uno nuevo (chocaría con la restricción UNIQUE de
                    // Id_Empresa+Ruc, ya que Ruc sigue en NULL).
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

                    $usuario->proveedores()->attach($proveedor->Id_Proveedor);
                }
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
            creadoPor: $solicitante->Id_Usuario,
        );
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

    public function reactivar(Usuario $usuario, Usuario $ejecutor, int $idEmpresa): void
{
    if (! $ejecutor->esSistemas($idEmpresa)) {
        throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden reactivar usuarios.');
    }

    $usuario->forceFill([
        'Activo' => true,
        'Modificado_Por' => $ejecutor->Id_Usuario,
        'Fecha_Modificacion' => now(),
    ])->save();
}

public function actualizarEmail(Usuario $usuario, string $nuevoEmail, Usuario $ejecutor, int $idEmpresa): void
{
    $puedeGestionar = $usuario->Tipo_Usuario === 'Interno'
        ? $ejecutor->esSistemas($idEmpresa)
        : ($ejecutor->esSistemas($idEmpresa) || $ejecutor->esAdmin($idEmpresa));

    if (! $puedeGestionar) {
        throw new AccessDeniedHttpException('No tiene permisos para editar este usuario.');
    }

    if (Usuario::where('Email', $nuevoEmail)->where('Id_Usuario', '!=', $usuario->Id_Usuario)->exists()) {
        throw ValidationException::withMessages([
            'email' => ['Ya existe otro usuario con ese correo.'],
        ]);
    }

    $usuario->forceFill([
        'Email' => $nuevoEmail,
        'Modificado_Por' => $ejecutor->Id_Usuario,
        'Fecha_Modificacion' => now(),
    ])->save();
}

public function actualizarRolEnEmpresa(Usuario $usuario, int $idEmpresa, int $idRol, Usuario $ejecutor): void
{
    if (! $ejecutor->esSistemas($idEmpresa)) {
        throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden cambiar roles.');
    }

    $vinculo = $usuario->usuarioEmpresas()->where('Id_Empresa', $idEmpresa)->where('Activo', true)->first();

    if (! $vinculo) {
        throw ValidationException::withMessages([
            'id_empresa' => ['El usuario no tiene acceso activo a esa empresa.'],
        ]);
    }

    $vinculo->forceFill([
        'Id_Rol' => $idRol,
        'Modificado_Por' => $ejecutor->Id_Usuario,
        'Fecha_Modificacion' => now(),
    ])->save();
}

/**
 * Reemplaza por completo las bodegas asignadas a un usuario Compras dentro
 * de una empresa (sync: borra las que ya no vengan en la lista, crea las
 * nuevas). Solo tiene efecto real sobre el rol Compras -> Admin/Sistemas
 * ignoran esto (siempre ven las 3 bodegas), pero no se bloquea la llamada
 * para permitir asignar bodegas ANTES de que Sistemas cambie el rol.
 */
public function actualizarBodegasAsignadas(Usuario $usuario, int $idEmpresa, array $codigosBodega, Usuario $ejecutor): void
{
    if (! $ejecutor->esSistemas($idEmpresa)) {
        throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden asignar bodegas.');
    }

    $vinculo = $usuario->usuarioEmpresas()->where('Id_Empresa', $idEmpresa)->where('Activo', true)->first();

    if (! $vinculo) {
        throw ValidationException::withMessages([
            'id_empresa' => ['El usuario no tiene acceso activo a esa empresa.'],
        ]);
    }

    $codigosValidos = ['CD-0001', 'CD-0002', 'CD-0003'];
    $codigosInvalidos = array_diff($codigosBodega, $codigosValidos);

    if (! empty($codigosInvalidos)) {
        throw ValidationException::withMessages([
            'codigos_bodega' => ['Código(s) de bodega inválido(s): ' . implode(', ', $codigosInvalidos)],
        ]);
    }

    DB::transaction(function () use ($usuario, $idEmpresa, $codigosBodega, $ejecutor) {
        UsuarioBodega::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Id_Empresa', $idEmpresa)
            ->whereNotIn('Cod_Almacen', $codigosBodega)
            ->delete();

        $existentes = UsuarioBodega::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Id_Empresa', $idEmpresa)
            ->pluck('Cod_Almacen')
            ->all();

        foreach (array_diff($codigosBodega, $existentes) as $codigo) {
            UsuarioBodega::create([
                'Id_Usuario' => $usuario->Id_Usuario,
                'Id_Empresa' => $idEmpresa,
                'Cod_Almacen' => $codigo,
                'Activo' => true,
                'Creado_Por' => $ejecutor->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);
        }
    });
}

public function quitarAccesoEmpresa(Usuario $usuario, int $idEmpresa, Usuario $ejecutor): void
{
    $puedeGestionar = $usuario->Tipo_Usuario === 'Interno'
        ? $ejecutor->esSistemas($idEmpresa)
        : ($ejecutor->esSistemas($idEmpresa) || $ejecutor->esAdmin($idEmpresa));

    if (! $puedeGestionar) {
        throw new AccessDeniedHttpException('No tiene permisos para modificar el acceso de este usuario en esa empresa.');
    }

    $vinculo = $usuario->usuarioEmpresas()->where('Id_Empresa', $idEmpresa)->where('Activo', true)->first();

    if (! $vinculo) {
        throw ValidationException::withMessages([
            'id_empresa' => ['El usuario no tiene acceso activo a esa empresa.'],
        ]);
    }

    if ($usuario->usuarioEmpresas()->where('Activo', true)->count() <= 1) {
        throw ValidationException::withMessages([
            'id_empresa' => ['No puede quitar el único acceso que le queda. Inactive el usuario completo en su lugar.'],
        ]);
    }

    $vinculo->forceFill(['Activo' => false])->save();
}

/**
 * Da acceso a un usuario EXISTENTE a una empresa adicional.
 * - Interno: requiere id_rol explícito, solo Sistemas puede otorgarlo.
 * - Proveedor: el rol siempre es "Proveedor"; Sistemas o Admin pueden otorgarlo.
 *   Si el usuario ya activó su cuenta, se crea de inmediato el "cascarón"
 *   del Proveedor para esa empresa (si aún no activó, se crea después,
 *   junto con las demás, al momento de activarCuenta()).
 */
public function otorgarAccesoEmpresa(Usuario $usuario, int $idEmpresa, Usuario $creador, ?int $idRol = null): void
{
    if ($usuario->Tipo_Usuario === 'Interno') {
    if (! $creador->esSistemasGlobal()) {
        throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden otorgar acceso a usuarios internos.');
    }
    if (! $idRol) {
        throw ValidationException::withMessages(['id_rol' => ['El rol es requerido para un usuario interno.']]);
    }

    } else {
        if (! $creador->esSistemas($idEmpresa) && ! $creador->esAdmin($idEmpresa)) {
            throw new AccessDeniedHttpException('No tiene permisos para otorgar acceso de proveedor en esa empresa.');
        }
        $idRol = Rol::where('Nombre_Rol', 'Proveedor')->value('Id_Rol');
    }

    $yaTieneAcceso = $usuario->usuarioEmpresas()
        ->where('Id_Empresa', $idEmpresa)
        ->where('Activo', true)
        ->exists();

    if ($yaTieneAcceso) {
        throw ValidationException::withMessages(['id_empresa' => ['El usuario ya tiene acceso a esa empresa.']]);
    }

    DB::transaction(function () use ($usuario, $idEmpresa, $idRol, $creador) {
        // Si ya existía un vínculo INACTIVO (el usuario tuvo acceso antes y
        // se lo quitaron), lo reactivamos en vez de intentar crear uno
        // nuevo. quitarAccesoEmpresa() hace soft-delete (Activo=false, la
        // fila nunca se borra), así que un create() de nuevo siempre choca
        // con la UNIQUE KEY (Id_Usuario, Id_Empresa).
        $vinculo = UsuarioEmpresa::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Id_Empresa', $idEmpresa)
            ->first();

        if ($vinculo) {
            $vinculo->forceFill([
                'Id_Rol' => $idRol,
                'Activo' => true,
                'Modificado_Por' => $creador->Id_Usuario,
                'Fecha_Modificacion' => now(),
            ])->save();
        } else {
            UsuarioEmpresa::create([
                'Id_Usuario' => $usuario->Id_Usuario,
                'Id_Empresa' => $idEmpresa,
                'Id_Rol' => $idRol,
                'Activo' => true,
                'Creado_Por' => $creador->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);
        }

        if ($usuario->Tipo_Usuario === 'Proveedor' && ! $usuario->Requiere_Cambio_Password) {
            $proveedor = Proveedor::create([
                'Id_Empresa' => $idEmpresa,
                'Email' => $usuario->Email,
                'Seccion_Actual' => 1,
                'Porcentaje_Completado_Ficha' => 0,
                'Fecha_Postulacion' => now(),
                'Activo' => true,
                'Fecha_Creacion' => now(),
            ]);

            $usuario->proveedores()->attach($proveedor->Id_Proveedor);
        }
    });
}
}