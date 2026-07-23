<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\ActivarCuentaRequest;
use App\Modules\Auth\Http\Requests\CambiarEmpresaRequest;
use App\Modules\Auth\Http\Requests\CambiarPasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\OlvidePasswordRequest;
use App\Modules\Auth\Http\Resources\UsuarioResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService,
        protected UsuarioService $usuarioService,
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $resultado = $this->authService->login(
            email: $request->validated('email'),
            password: $request->validated('password'),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'usuario' => new UsuarioResource($resultado['usuario']),
            'token' => $resultado['token'],
            'id_empresa_activa' => $resultado['id_empresa_activa'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user(), $request->user()->currentAccessToken());

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $sesion = $this->authService->sesionActual($usuario, $usuario->currentAccessToken());

        return response()->json([
            'usuario' => new UsuarioResource($usuario->load(['empresas' => fn ($q) => $q->wherePivot('Activo', true)])),
            'id_empresa_activa' => $sesion?->Id_Empresa_Activa,
        ]);
    }

    public function cambiarEmpresa(CambiarEmpresaRequest $request): JsonResponse
    {
        $usuario = $request->user();

        $sesion = $this->authService->cambiarEmpresa(
            usuario: $usuario,
            accessToken: $usuario->currentAccessToken(),
            idEmpresa: $request->validated('id_empresa'),
        );

        return response()->json([
            'message' => 'Empresa activa actualizada.',
            'id_empresa_activa' => $sesion->Id_Empresa_Activa,
        ]);
    }

    public function cambiarPassword(CambiarPasswordRequest $request): JsonResponse
    {
        $this->usuarioService->cambiarPassword(
            usuario: $request->user(),
            passwordActual: $request->validated('password_actual'),
            passwordNueva: $request->validated('password_nueva'),
        );

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    public function olvidePassword(OlvidePasswordRequest $request): JsonResponse
    {
        $this->usuarioService->olvidePassword($request->validated('email'));

        return response()->json([
            'message' => 'Se envió un código de recuperación al correo registrado.',
        ]);
    }

    public function activarCuenta(ActivarCuentaRequest $request): JsonResponse
    {
        $this->usuarioService->activarCuenta(
            email: $request->validated('email'),
            codigo: $request->validated('codigo'),
            passwordNueva: $request->validated('password_nueva'),
            datosPerfil: [
                'nombre_completo' => $request->validated('nombre_completo'),
                'cargo' => $request->validated('cargo'),
                'telefono' => $request->validated('telefono'),
            ],
        );

        return response()->json([
            'message' => 'Cuenta activada correctamente. Ya puedes iniciar sesión.',
        ]);
    }
}
