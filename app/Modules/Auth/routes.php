<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\IpBloqueadaController;
use App\Modules\Auth\Http\Controllers\DashboardSistemasController;
use App\Modules\Auth\Http\Controllers\RolController;
use App\Modules\Auth\Http\Controllers\UsuarioController;
use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    /**
     * Los 3 endpoints anónimos van con throttle propio, aparte del límite
     * general de la API.
     *
     * El bloqueo por cuenta (3 fallos) y por IP (5 correos inexistentes) ya
     * vive en AuthService, pero eso frena a quien ADIVINA credenciales; no
     * frena a quien simplemente inunda el endpoint para que el servidor no
     * atienda a nadie más. Cada login gasta un Hash::check, que por diseño
     * es lento -> sin este techo, unas pocas peticiones por segundo bastan
     * para dejar el portal sin CPU.
     *
     * Los cuatro usan limitadores CON NOMBRE (ver
     * AppServiceProvider::registrarLimitesDeCuenta) y no un 'throttle:3,10'
     * suelto. Motivo: sin nombre, la clave del conteo es `dominio|IP`, así
     * que el cupo lo comparte toda una red y una oficina detrás de una IP
     * pública se bloqueaba entre sí. Los limitadores con nombre cuentan por
     * CORREO (estricto, protege a la persona) y por IP (holgado, solo frena
     * un abuso masivo).
     */
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/olvide-password', [AuthController::class, 'olvidePassword'])->middleware('throttle:recuperar-password');
    Route::post('/activar-cuenta', [AuthController::class, 'activarCuenta'])->middleware('throttle:activar-cuenta');
    // Paso 1 de la pantalla de activación. Límite más holgado que el de
    // activar: acá no se manda ningún correo ni se cambia nada, solo se
    // comprueba el código, y la persona puede corregir un tipeo varias veces.
    Route::post('/validar-codigo', [AuthController::class, 'validarCodigoActivacion'])->middleware('throttle:validar-codigo');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/cambiar-empresa', [AuthController::class, 'cambiarEmpresa']);
        Route::post('/cambiar-password', [AuthController::class, 'cambiarPassword']);
        Route::get('/roles', [RolController::class, 'index']);

        // Bandeja de IP bloqueadas por fuerza bruta (solo Sistemas, lo
        // valida el controller). El bloqueo no se levanta solo.
        Route::get('/ips-bloqueadas', [IpBloqueadaController::class, 'index']);
        Route::post('/ips-bloqueadas/{idIpBloqueada}/desbloquear', [IpBloqueadaController::class, 'desbloquear']);
    });
});

Route::get('/dashboard/sistemas', [DashboardSistemasController::class, 'resumen'])
    ->middleware(['auth:sanctum', EmpresaActiva::class]);

Route::prefix('usuarios')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        // Usuarios internos (staff): solo rol Sistemas puede crear
        Route::get('/internos', [UsuarioController::class, 'indexInternos']);
        Route::post('/internos', [UsuarioController::class, 'storeInterno']);
        Route::get('/internos/{usuario}', [UsuarioController::class, 'showInterno']);

        // Usuarios externos (Proveedores): rol Sistemas o Admin pueden crear
        Route::get('/externos', [UsuarioController::class, 'indexExternos']);
        Route::post('/externos', [UsuarioController::class, 'storeProveedor']);

        /**
         * Carga masiva desde Excel: SOLO rol Sistemas (lo valida
         * UsuarioService::crearUsuariosProveedorEnLote, no alcanza con
         * llegar hasta acá).
         *
         * Va con techo propio de peticiones porque una sola llamada puede
         * crear hasta 500 usuarios y encolar 500 correos: sin esto, un
         * doble clic o un script equivocado repite la carga completa. Al
         * ser una ruta autenticada, el conteo de Laravel es POR USUARIO y
         * no por IP, así que dos personas de Sistemas en la misma oficina
         * no se quitan el cupo entre ellas.
         */
        Route::post('/externos/lote', [UsuarioController::class, 'storeProveedoresLote'])
            ->middleware('throttle:6,1');

        Route::get('/externos/{usuario}', [UsuarioController::class, 'showExterno']);

        // Común a ambos
        Route::patch('/{usuario}/inactivar', [UsuarioController::class, 'inactivar']);
        Route::post('/{usuario}/reenviar-codigo', [UsuarioController::class, 'reenviarCodigo']);
        Route::patch('/{usuario}/reactivar', [UsuarioController::class, 'reactivar']);
        Route::post('/{usuario}/reenviar-activacion', [UsuarioController::class, 'reenviarActivacion']);
        Route::post('/{usuario}/empresas', [UsuarioController::class, 'agregarEmpresa']);
        Route::put('/{usuario}/email', [UsuarioController::class, 'actualizarEmail']);
        Route::put('/{usuario}/empresas/{empresa}', [UsuarioController::class, 'actualizarRolEnEmpresa']);
        Route::put('/{usuario}/empresas/{empresa}/bodegas', [UsuarioController::class, 'actualizarBodegasEnEmpresa']);
        Route::delete('/{usuario}/empresas/{empresa}', [UsuarioController::class, 'quitarAccesoEmpresa']);
    });