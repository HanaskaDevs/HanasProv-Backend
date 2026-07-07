<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica que la Sesion ligada al token actual tenga una empresa activa
 * seleccionada (Sesion.Id_Empresa_Activa) antes de continuar.
 * La empresa activa se cambia vía POST /auth/cambiar-empresa, sin
 * necesidad de volver a loguearse (igual que en Business Central).
 */
class EmpresaActiva
{
    public function __construct(protected AuthService $authService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        $accessToken = $usuario?->currentAccessToken();

        $sesion = $accessToken ? $this->authService->sesionActual($usuario, $accessToken) : null;

        if (! $sesion) {
            return response()->json([
                'message' => 'No se encontró una sesión activa válida.',
            ], 401);
        }

        if (! $sesion->Id_Empresa_Activa) {
            return response()->json([
                'message' => 'Debe seleccionar una empresa activa para continuar.',
                'empresas_disponibles' => $usuario->empresas()->wherePivot('Activo', true)->get(['Empresa.Id_Empresa', 'Razon_Social']),
            ], 409);
        }

        $tieneAcceso = $usuario->empresas()
            ->where('Empresa.Id_Empresa', $sesion->Id_Empresa_Activa)
            ->wherePivot('Activo', true)
            ->exists();

        if (! $tieneAcceso) {
            return response()->json([
                'message' => 'No tiene acceso a la empresa seleccionada.',
            ], 403);
        }

        $request->attributes->set('id_empresa_activa', $sesion->Id_Empresa_Activa);

        return $next($request);
    }
}
