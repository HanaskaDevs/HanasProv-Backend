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

        // SIEMPRE la misma respuesta, exista o no la cuenta. El service ya
        // corta en silencio los casos que no corresponden -> si acá se
        // devolviera un error distinto para un correo desconocido, este
        // endpoint serviría para averiguar qué correos están registrados.
        return response()->json([
            'message' => 'Si el correo está registrado, te enviamos un código de recuperación.',
        ]);
    }

    /**
     * Paso 1 de la pantalla de activación: comprueba el código antes de que
     * la persona llene el resto, y dice si además hay que pedirle los datos
     * de su empresa (solo a un proveedor en su primera activación).
     *
     * Es anónimo por necesidad -- quien activa todavía no tiene sesión --
     * pero solo responde a quien ya trae el código correcto, y ante
     * cualquier problema devuelve el mismo mensaje que un código inválido,
     * así no sirve para averiguar qué correos están registrados.
     */
    public function validarCodigoActivacion(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'codigo' => ['required', 'string', 'max:10'],
        ]);

        return response()->json(
            $this->usuarioService->validarCodigoActivacion($datos['email'], $datos['codigo'])
        );
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
                'ruc' => $request->validated('ruc'),
                'razon_social' => $request->validated('razon_social'),
            ],
        );

        return response()->json([
            'message' => 'Cuenta activada correctamente. Ya puedes iniciar sesión.',
        ]);
    }
}
