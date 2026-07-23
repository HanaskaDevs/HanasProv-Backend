<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Configuraciones\Http\Controllers\BotReglaController;
use App\Modules\Configuraciones\Http\Controllers\GuiaPasoController;
use App\Modules\Configuraciones\Http\Controllers\HomeSlideController;
use App\Modules\Configuraciones\Http\Controllers\LoginImagenController;
use App\Modules\Configuraciones\Http\Controllers\PublicConfigController;
use Illuminate\Support\Facades\Route;

// Públicas: sin autenticación, consumidas por Landing/Login/Tour antes de loguearse.
Route::prefix('public-config')->group(function () {
    Route::get('/home-slides', [PublicConfigController::class, 'homeSlides']);
    Route::get('/login-imagen', [PublicConfigController::class, 'loginImagen']);
    Route::get('/guia-pasos', [PublicConfigController::class, 'guiaPasos']);
});

// Administración: solo Sistemas (la verificación real ocurre en el service).
Route::prefix('configuraciones')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/home-slides', [HomeSlideController::class, 'index']);
        Route::post('/home-slides', [HomeSlideController::class, 'store']);
        Route::put('/home-slides/{slide}', [HomeSlideController::class, 'update']); // POST + _method=PUT por el archivo
        Route::delete('/home-slides/{slide}', [HomeSlideController::class, 'destroy']);

        Route::get('/login-imagen', [LoginImagenController::class, 'show']);
        Route::post('/login-imagen', [LoginImagenController::class, 'update']);

        Route::get('/bot-reglas', [BotReglaController::class, 'index']);
        Route::post('/bot-reglas', [BotReglaController::class, 'store']);
        Route::put('/bot-reglas/{regla}', [BotReglaController::class, 'update']);
        Route::delete('/bot-reglas/{regla}', [BotReglaController::class, 'destroy']);

        Route::get('/guia-pasos', [GuiaPasoController::class, 'index']);
        Route::post('/guia-pasos', [GuiaPasoController::class, 'store']);
        Route::put('/guia-pasos/{paso}', [GuiaPasoController::class, 'update']);
        Route::delete('/guia-pasos/{paso}', [GuiaPasoController::class, 'destroy']);
    });