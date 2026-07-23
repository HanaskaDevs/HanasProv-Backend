<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Reclamos\Http\Controllers\ReclamoController;
use App\Modules\Reclamos\Http\Controllers\ReclamoProveedorController;
use Illuminate\Support\Facades\Route;

Route::prefix('reclamos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/abiertos', [ReclamoController::class, 'abiertos']);
        Route::get('/cerrados', [ReclamoController::class, 'cerrados']);
        Route::get('/buscar-proveedores', [ReclamoController::class, 'buscarProveedores']);
        Route::get('/imagenes/{imagen}/ver', [ReclamoController::class, 'verImagen']);
        Route::get('/{reclamo}', [ReclamoController::class, 'show']);
        Route::post('/', [ReclamoController::class, 'store']);
        Route::post('/{reclamo}/responder', [ReclamoController::class, 'responder']);
        Route::patch('/{reclamo}/cerrar', [ReclamoController::class, 'cerrar']);
    });

Route::prefix('mis-reclamos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/abiertos', [ReclamoProveedorController::class, 'abiertos']);
        Route::get('/cerrados', [ReclamoProveedorController::class, 'cerrados']);
        Route::get('/imagenes/{imagen}/ver', [ReclamoProveedorController::class, 'verImagen']);
        Route::get('/{reclamo}', [ReclamoProveedorController::class, 'show']);
        Route::post('/{reclamo}/responder', [ReclamoProveedorController::class, 'responder']);
    });
