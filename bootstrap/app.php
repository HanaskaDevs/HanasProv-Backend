<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // El portal se publica detras del proxy TLS de redes (10.100.60.6).
        // Sin esto Laravel no reconoce el HTTPS del cliente y genera las URLs
        // de /media con http://, provocando Mixed Content en el navegador.
        $middleware->trustProxies(at: "*");

        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        /*
         * Techo general para TODA la API.
         *
         * Antes no había ninguno: las 176 rutas autenticadas se podían
         * llamar tan rápido como aguantara el servidor, así que una sola
         * sesión válida alcanzaba para dejar el portal sin CPU (el proceso
         * atiende de a una petición por vez). Las rutas sensibles tienen
         * además su propio límite, más bajo, encima de este.
         *
         * 120/min por usuario autenticado es holgado para uso real: la
         * pantalla más pesada (Modo TV) hace 4 peticiones cada 20 segundos.
         * Cuando no hay sesión el límite cae sobre la IP.
         */
        // 'api' = el limitador con nombre que define AppServiceProvider.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
