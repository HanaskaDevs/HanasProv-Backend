<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\BitacoraAcceso;
use App\Modules\Auth\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Autentica un usuario por email/password y emite un token Sanctum.
     * No selecciona empresa activa todavía: eso se hace en un segundo paso
     * si el usuario tiene acceso a más de una empresa.
     */
    public function login(string $email, string $password, ?string $ip = null): array
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

        $token = $usuario->createToken('api')->plainTextToken;

        return [
            'usuario' => $usuario->load('empresas'),
            'token' => $token,
        ];
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
