<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza que todas las rutas de la API respondan siempre en JSON,
 * sin importar el header Accept que mande el cliente. Sin esto, un
 * request sin "Accept: application/json" ante un error de validación
 * termina en un redirect (comportamiento pensado para formularios web),
 * no en un JSON con el error.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
