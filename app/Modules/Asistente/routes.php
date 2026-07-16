<?php

use App\Modules\Asistente\Http\Controllers\AsistenteController;
use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use Illuminate\Support\Facades\Route;

Route::prefix('asistente')
    ->middleware(['auth:sanctum', EmpresaActiva::class, 'throttle:15,1'])
    ->group(function () {
        Route::post('/mensaje', [AsistenteController::class, 'enviarMensaje']);
    });