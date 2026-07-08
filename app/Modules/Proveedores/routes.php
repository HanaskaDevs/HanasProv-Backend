<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Proveedores\Http\Controllers\FichaProveedorController;
use App\Modules\Proveedores\Http\Controllers\ProveedorController;
use Illuminate\Support\Facades\Route;

Route::prefix('proveedores')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [ProveedorController::class, 'index']);
        Route::post('/', [ProveedorController::class, 'store']);
        Route::get('/{proveedor}', [ProveedorController::class, 'show']);
    });

// Ficha de Proveedor progresiva: exclusiva del propio usuario externo.
Route::prefix('mi-ficha')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [FichaProveedorController::class, 'show']);
        Route::put('/seccion-1', [FichaProveedorController::class, 'seccion1']);
        Route::put('/seccion-2', [FichaProveedorController::class, 'seccion2']);
        Route::put('/seccion-3', [FichaProveedorController::class, 'seccion3']);
    });
