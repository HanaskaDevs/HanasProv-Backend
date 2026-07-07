<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\UsuarioController;
use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/olvide-password', [AuthController::class, 'olvidePassword']);
    Route::post('/activar-cuenta', [AuthController::class, 'activarCuenta']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/cambiar-empresa', [AuthController::class, 'cambiarEmpresa']);
        Route::post('/cambiar-password', [AuthController::class, 'cambiarPassword']);
    });
});

Route::prefix('usuarios')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/internos', [UsuarioController::class, 'index']);
        Route::post('/internos', [UsuarioController::class, 'storeInterno']);
        Route::get('/internos/{usuario}', [UsuarioController::class, 'show']);
        Route::post('/proveedores', [UsuarioController::class, 'storeProveedor']);
        Route::patch('/{usuario}/inactivar', [UsuarioController::class, 'inactivar']);
    });
