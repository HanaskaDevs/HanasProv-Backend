<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\BitacoraAcceso;
use App\Modules\Auth\Models\Sesion;
use App\Modules\Auth\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AuthService
{
    protected const HORAS_VIGENCIA_SESION = 12;

    /**
     * Autentica un usuario por email/password, emite un token Sanctum, y crea
     * el registro de Sesion correspondiente (donde se rastrea la empresa activa).
     * Si el usuario solo tiene acceso a una empresa, se autoselecciona.
     */
    public function login(string $email, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        $usuario = Usuario::where('Email', $email)->where('Activo', true)->first();

        if (! $usuario || ! Hash::check($password, $usuario->Password_Hash)) {
            $this->registrarIntento($email, $ip, exito: false);

            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son válidas.'],
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
            'usuario' => $usuario->load('empresas'),
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

    protected function registrarIntento(string $email, ?string $ip, bool $exito, ?int $idUsuario = null): void
    {
        BitacoraAcceso::create([
            'Id_Usuario' => $idUsuario,
            'Email_Intento' => $email,
            'Tipo_Evento' => $exito ? 'Login_Exitoso' : 'Login_Fallido',
            'Ip_Origen' => $ip,
            'Fecha_Evento' => now(),
        ]);
    }
}
