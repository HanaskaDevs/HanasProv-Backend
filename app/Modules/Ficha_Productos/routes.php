<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Ficha_Productos\Http\Controllers\ProductoController;
use Illuminate\Support\Facades\Route;

Route::prefix('mis-productos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [ProductoController::class, 'index']);
        Route::post('/', [ProductoController::class, 'store']);
        Route::post('/{producto}/documentos/{tipoDocumento}', [ProductoController::class, 'subirDocumento']);
    });