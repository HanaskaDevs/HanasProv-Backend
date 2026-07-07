<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\CambiarPasswordInicialRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\OlvidePasswordRequest;
use App\Modules\Auth\Http\Resources\UsuarioResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
        );

        // El frontend debe revisar "requiere_cambio_password" en la respuesta:
        // si viene true, debe mostrar el panel de nueva contraseña en vez del panel normal.
        return response()->json([
            'usuario' => new UsuarioResource($resultado['usuario']),
            'token' => $resultado['token'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(
            new UsuarioResource($request->user()->load('empresas'))
        );
    }

    public function olvidePassword(OlvidePasswordRequest $request): JsonResponse
    {
        $this->usuarioService->olvidePassword($request->validated('email'));

        return response()->json([
            'message' => 'Se envió una contraseña temporal al correo registrado.',
        ]);
    }

    public function cambiarPasswordInicial(CambiarPasswordInicialRequest $request): JsonResponse
    {
        $usuario = $request->user();

        if (! Hash::check($request->validated('password_actual'), $usuario->Password_Hash)) {
            throw ValidationException::withMessages([
                'password_actual' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $this->usuarioService->cambiarPasswordInicial($usuario, $request->validated('password_nueva'));

        return response()->json([
            'message' => 'Contraseña actualizada. Por favor inicia sesión de nuevo con tu nueva contraseña.',
        ]);
    }
}
