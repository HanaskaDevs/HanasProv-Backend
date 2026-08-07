<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Ficha_Productos\Http\Controllers\ProductoController;
use App\Modules\Ficha_Productos\Http\Controllers\SolicitudCambioPrecioController;
use App\Modules\Ficha_Productos\Http\Controllers\UnidadPresentacionController;
use Illuminate\Support\Facades\Route;

Route::prefix('mis-productos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [ProductoController::class, 'index']);
        Route::post('/', [ProductoController::class, 'store']);
        Route::get('/unidades-presentacion', [UnidadPresentacionController::class, 'index']);
        Route::get('/documentos/{documentoProducto}/ver', [ProductoController::class, 'descargarDocumento']);
        Route::post('/{producto}/documentos/{tipoDocumento}', [ProductoController::class, 'subirDocumento']);
        Route::get('/resumen-registro', [ProductoController::class, 'resumenRegistro']);
        Route::post('/registrar', [ProductoController::class, 'registrar']);
        Route::post('/{producto}/confirmar-correccion', [ProductoController::class, 'confirmarCorreccionProducto']);
        Route::patch('/{producto}/precio', [SolicitudCambioPrecioController::class, 'solicitar']);
        Route::delete('/masivo', [ProductoController::class, 'destroyMasivo']);
        Route::delete('/{producto}', [ProductoController::class, 'destroy']);
        Route::delete('/documentos/{documentoProducto}', [ProductoController::class, 'destroyDocumento']);
    });

// Admin/Calidad de la empresa: revisar solicitudes de cambio de precio.
Route::prefix('cambios-precio')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [SolicitudCambioPrecioController::class, 'index']);
        Route::post('/{solicitud}/aprobar', [SolicitudCambioPrecioController::class, 'aprobar']);
        Route::post('/{solicitud}/rechazar', [SolicitudCambioPrecioController::class, 'rechazar']);
    });