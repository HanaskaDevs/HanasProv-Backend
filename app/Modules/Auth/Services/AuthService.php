<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\BitacoraAcceso;
use App\Modules\Auth\Models\Sesion;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\EstadoProveedor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AuthService
{
    protected const HORAS_VIGENCIA_SESION = 12;

    /**
     * Único mensaje para "tu cuenta existe y la contraseña es correcta, pero
     * no podés entrar". Lo usan el login y también el middleware
     * EmpresaActiva (cuando la suspensión afecta a una sola de las empresas
     * del usuario), así el proveedor lee siempre lo mismo y no queda
     * adivinando si es un problema de contraseña.
     */
    public const MENSAJE_ACCESO_SUSPENDIDO = 'Tu acceso al portal está suspendido. Por favor contáctate con el administrador para reactivarlo.';

    /**
     * Autentica un usuario por email/password, emite un token Sanctum, y crea
     * el registro de Sesion correspondiente (donde se rastrea la empresa activa).
     * Si el usuario solo tiene acceso a una empresa, se autoselecciona.
     */
    public function login(string $email, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        // Ya NO se filtra por Activo en la consulta: hace falta poder
        // distinguir "no existe / contraseña mal" de "existe pero está
        // bloqueado", para poder decirle al proveedor que hable con el
        // administrador en vez del genérico "credenciales no válidas".
        $usuario = Usuario::where('Email', $email)->first();

        if (! $usuario || ! Hash::check($password, $usuario->Password_Hash)) {
            $this->registrarIntento($email, $ip, exito: false);

            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son válidas.'],
            ]);
        }

        // Desde acá la contraseña ES la correcta -> explicar el motivo real no
        // le revela nada a un desconocido (quien no sabe la clave sigue
        // recibiendo el mensaje genérico de arriba).
        if (! $usuario->Activo) {
            $this->registrarIntento($email, $ip, exito: false, idUsuario: $usuario->Id_Usuario, tipoEvento: 'Login_Bloqueado');

            throw ValidationException::withMessages([
                'email' => [self::MENSAJE_ACCESO_SUSPENDIDO],
            ]);
        }

        // Proveedor suspendido en TODAS sus empresas: no tiene a dónde entrar.
        // Si le queda al menos una al día, entra normal y el corte por empresa
        // lo hace el middleware EmpresaActiva.
        if ($usuario->Tipo_Usuario === 'Proveedor' && ! $this->tieneAlgunaEmpresaDisponible($usuario)) {
            $this->registrarIntento($email, $ip, exito: false, idUsuario: $usuario->Id_Usuario, tipoEvento: 'Login_Bloqueado');

            throw ValidationException::withMessages([
                'email' => [self::MENSAJE_ACCESO_SUSPENDIDO],
            ]);
        }

        $this->registrarIntento($email, $ip, exito: true, idUsuario: $usuario->Id_Usuario);

        $usuario->forceFill(['Ultimo_Acceso' => now()])->save();

        $tokenResult = $usuario->createToken('api');
        $token = $tokenResult->plainTextToken;
        $idTokenAccess = $tokenResult->accessToken->id;

        $empresasActivas = $usuario->empresas()->wherePivot('Activo', true)->get();
        $idEmpresaActiva = $empresasActivas->count() === 1 ? $empresasActivas->first()->Id_Empresa : null;

        Sesion::create([
            'Id_Usuario' => $usuario->Id_Usuario,
            'Token' => (string) $idTokenAccess,
            'Ip_Origen' => $ip,
            'Dispositivo' => $userAgent,
            'Fecha_Inicio' => now(),
            'Fecha_Expiracion' => now()->addHours(self::HORAS_VIGENCIA_SESION),
            'Activa' => true,
            'Id_Empresa_Activa' => $idEmpresaActiva,
        ]);

        return [
            'usuario' => $usuario->load(['empresas' => fn($q) => $q->wherePivot('Activo', true)]),
            'token' => $token,
            'id_empresa_activa' => $idEmpresaActiva,
        ];
    }

    /**
     * Cierra la Sesion ligada al token actual y revoca ese token Sanctum.
     */
    public function logout(Usuario $usuario, PersonalAccessToken $accessToken): void
    {
        Sesion::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Token', (string) $accessToken->id)
            ->update(['Activa' => false]);

        $accessToken->delete();
    }

    /**
     * Cambia la empresa activa de la sesión actual SIN cerrar sesión
     * (equivalente a cambiar de compañía en Business Central).
     */
    public function cambiarEmpresa(Usuario $usuario, PersonalAccessToken $accessToken, int $idEmpresa): Sesion
    {
        $tieneAcceso = $usuario->empresas()
            ->where('Empresa.Id_Empresa', $idEmpresa)
            ->wherePivot('Activo', true)
            ->exists();

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('No tiene acceso a la empresa seleccionada.');
        }

        $sesion = Sesion::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Token', (string) $accessToken->id)
            ->where('Activa', true)
            ->firstOrFail();

        $sesion->forceFill(['Id_Empresa_Activa' => $idEmpresa])->save();

        return $sesion;
    }

    /**
     * Resuelve la Sesion asociada al token actualmente autenticado.
     */
    public function sesionActual(Usuario $usuario, PersonalAccessToken $accessToken): ?Sesion
    {
        return Sesion::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Token', (string) $accessToken->id)
            ->where('Activa', true)
            ->first();
    }

    /**
     * $tipoEvento permite distinguir en la bitácora un intento con la clave
     * equivocada ('Login_Fallido') de uno con la clave correcta pero la
     * cuenta bloqueada ('Login_Bloqueado') -> son dos problemas distintos y
     * conviene poder separarlos al revisar accesos.
     */
    /**
     * ¿Le queda al usuario proveedor alguna empresa a la que entrar?
     *
     * Cuenta como disponible una empresa donde su Proveedor NO esté
     * Suspendido, y también una donde todavía no exista Proveedor (usuario
     * recién creado que aún no completó su Ficha: no hay nada que suspender).
     */
    protected function tieneAlgunaEmpresaDisponible(Usuario $usuario): bool
    {
        $idsEmpresas = $usuario->usuarioEmpresas()->where('Activo', true)->pluck('Id_Empresa');

        if ($idsEmpresas->isEmpty()) {
            // Sin ningún vínculo de empresa el bloqueo no aplica -> ese caso
            // lo resuelve después EmpresaActiva pidiendo elegir empresa.
            return true;
        }

        $proveedoresPorEmpresa = $usuario->proveedores()
            ->whereIn('Proveedor.Id_Empresa', $idsEmpresas)
            ->get()
            ->keyBy('Id_Empresa');

        foreach ($idsEmpresas as $idEmpresa) {
            $proveedor = $proveedoresPorEmpresa->get($idEmpresa);

            if (! $proveedor || (int) $proveedor->Id_Estado_Proveedor !== EstadoProveedor::SUSPENDIDO) {
                return true;
            }
        }

        return false;
    }

    protected function registrarIntento(string $email, ?string $ip, bool $exito, ?int $idUsuario = null, ?string $tipoEvento = null): void
    {
        BitacoraAcceso::create([
            'Id_Usuario' => $idUsuario,
            'Email_Intento' => $email,
            'Tipo_Evento' => $tipoEvento ?? ($exito ? 'Login_Exitoso' : 'Login_Fallido'),
            'Ip_Origen' => $ip,
            'Fecha_Evento' => now(),
        ]);
    }
}