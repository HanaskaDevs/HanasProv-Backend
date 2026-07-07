<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a rutas de negocio mientras el usuario siga con
 * una contraseña temporal (Requiere_Cambio_Password = true).
 * Las rutas de login, logout, me y cambiar-password-inicial NO llevan
 * este middleware para no generar un candado sin salida.
 */
class RequierePasswordActualizada
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario && $usuario->Requiere_Cambio_Password) {
            return response()->json([
                'message' => 'Debe establecer una nueva contraseña antes de continuar.',
                'requiere_cambio_password' => true,
            ], 423);
        }

        return $next($request);
    }
}
