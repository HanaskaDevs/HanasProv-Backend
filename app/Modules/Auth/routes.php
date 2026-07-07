<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\UsuarioController;
use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Auth\Http\Middleware\RequierePasswordActualizada;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/olvide-password', [AuthController::class, 'olvidePassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        // Sin RequierePasswordActualizada a propósito: es la única ruta
        // protegida a la que un usuario con clave temporal debe poder llegar.
        Route::post('/cambiar-password-inicial', [AuthController::class, 'cambiarPasswordInicial']);
    });
});

Route::prefix('usuarios')
    ->middleware(['auth:sanctum', RequierePasswordActualizada::class, EmpresaActiva::class])
    ->group(function () {
        Route::post('/internos', [UsuarioController::class, 'storeInterno']);
        Route::post('/proveedores', [UsuarioController::class, 'storeProveedor']);
        Route::patch('/{usuario}/inactivar', [UsuarioController::class, 'inactivar']);
    });
