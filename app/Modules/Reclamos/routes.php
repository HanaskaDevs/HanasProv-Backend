<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Reclamos\Http\Controllers\HistorialReclamosController;
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

/**
 * Reporte "Historial de reclamos" (12-nov-2026): solo lectura y descarga
 * en PDF, para Calidad, Admin y Sistemas (el permiso lo verifica
 * HistorialReclamosService::verificarAcceso).
 *
 * Cuelga de /reportes y no de /reclamos porque en el portal es un reporte
 * -se entra desde el área Reportes-, aunque el código viva en este módulo
 * por los modelos que usa.
 */
Route::prefix('reportes/historial-reclamos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [HistorialReclamosController::class, 'proveedores']);
        Route::get('/proveedor/{proveedor}', [HistorialReclamosController::class, 'porProveedor']);
        Route::get('/proveedor/{proveedor}/pdf', [HistorialReclamosController::class, 'pdfProveedor']);
        Route::get('/reclamo/{reclamo}/pdf', [HistorialReclamosController::class, 'pdfReclamo']);
    });
