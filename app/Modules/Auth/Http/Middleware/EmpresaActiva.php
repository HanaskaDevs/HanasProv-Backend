<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica que el usuario autenticado tenga una empresa activa seleccionada
 * en su sesión antes de continuar. Ver Sesion::Id_Empresa_Activa.
 *
 * TODO: definir cómo se resuelve la "empresa activa" para requests con token
 * Sanctum (header custom X-Empresa-Id, claim en el token, etc.)
 */
class EmpresaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $idEmpresa = $request->header('X-Empresa-Id');

        if (! $idEmpresa) {
            return response()->json([
                'message' => 'Debe seleccionar una empresa activa para continuar.',
            ], 409);
        }

        $usuario = $request->user();

        $tieneAcceso = $usuario?->empresas()
            ->where('Empresa.Id_Empresa', $idEmpresa)
            ->wherePivot('Activo', true)
            ->exists();

        if (! $tieneAcceso) {
            return response()->json([
                'message' => 'No tiene acceso a la empresa seleccionada.',
            ], 403);
        }

        $request->attributes->set('id_empresa_activa', $idEmpresa);

        return $next($request);
    }
}
