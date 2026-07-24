<?php

use App\Modules\Auditorias\Http\Controllers\AuditoriaController;
use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use Illuminate\Support\Facades\Route;

Route::prefix('auditorias')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/tipos', [AuditoriaController::class, 'tiposAuditoria']);
        Route::get('/proveedores', [AuditoriaController::class, 'proveedores']);
        Route::post('/iniciar', [AuditoriaController::class, 'iniciar']);
        Route::get('/{auditoria}', [AuditoriaController::class, 'mostrar']);
        Route::post('/{auditoria}/respuestas', [AuditoriaController::class, 'guardarRespuesta']);
        Route::post('/{auditoria}/finalizar', [AuditoriaController::class, 'finalizar']);
    });