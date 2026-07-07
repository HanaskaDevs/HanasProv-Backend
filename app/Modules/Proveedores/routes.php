<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Auth\Http\Middleware\RequierePasswordActualizada;
use App\Modules\Proveedores\Http\Controllers\ProveedorController;
use Illuminate\Support\Facades\Route;

Route::prefix('proveedores')
    ->middleware(['auth:sanctum', RequierePasswordActualizada::class, EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [ProveedorController::class, 'index']);
        Route::post('/', [ProveedorController::class, 'store']);
        Route::get('/{proveedor}', [ProveedorController::class, 'show']);
    });
