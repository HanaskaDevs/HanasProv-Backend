<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\UsuarioController;
use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use Illuminate\Support\Facades\Route;
use App\Modules\Auth\Http\Controllers\RolController;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/olvide-password', [AuthController::class, 'olvidePassword']);
    Route::post('/activar-cuenta', [AuthController::class, 'activarCuenta']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/cambiar-empresa', [AuthController::class, 'cambiarEmpresa']);
        Route::post('/cambiar-password', [AuthController::class, 'cambiarPassword']);
        Route::get('/roles', [RolController::class, 'index']);
    });
});

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
        Route::get('/externos/{usuario}', [UsuarioController::class, 'showExterno']);

        // Común a ambos: solo rol Sistemas puede inactivar
        Route::patch('/{usuario}/inactivar', [UsuarioController::class, 'inactivar']);
    });
