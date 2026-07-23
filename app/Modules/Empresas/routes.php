<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Empresas\Http\Controllers\EmpresaController;
use Illuminate\Support\Facades\Route;

Route::prefix('empresas')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [EmpresaController::class, 'index']);
        Route::post('/', [EmpresaController::class, 'store']);
        Route::put('/{empresa}', [EmpresaController::class, 'update']);
        Route::delete('/{empresa}', [EmpresaController::class, 'destroy']);
        Route::patch('/{empresa}/activar', [EmpresaController::class, 'activar']);
    });