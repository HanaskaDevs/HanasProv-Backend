<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Documentos_Proveedor\Http\Controllers\DocumentoProveedorController;
use App\Modules\Documentos_Proveedor\Http\Controllers\ReporteCaducidadController;
use Illuminate\Support\Facades\Route;

Route::prefix('mi-documentos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [DocumentoProveedorController::class, 'index']);
        Route::post('/registrar', [DocumentoProveedorController::class, 'registrar']);
        Route::post('/confirmar-correcciones', [DocumentoProveedorController::class, 'confirmarCorrecciones']);
        Route::post('/documento/{documentoProveedor}/reemplazar', [DocumentoProveedorController::class, 'reemplazar']);
        Route::delete('/documento/{documentoProveedor}', [DocumentoProveedorController::class, 'borrar']);
        Route::get('/plantilla/{tipoDocumento}', [DocumentoProveedorController::class, 'plantilla']);
        Route::post('/{tipoDocumento}', [DocumentoProveedorController::class, 'subir']);
        Route::get('/{documentoProveedor}/descargar', [DocumentoProveedorController::class, 'descargar']);
    });

/**
 * Reporte de caducidad de documentos (Admin / Calidad / Sistemas).
 *
 * Va fuera del grupo 'mi-documentos' a propósito: ese prefijo es del propio
 * proveedor sobre SUS documentos, y esto es lo contrario -> personal interno
 * mirando los documentos de todos los proveedores de la empresa.
 */
Route::get('/reportes/caducidad-documentos', [ReporteCaducidadController::class, 'index'])
    ->middleware(['auth:sanctum', EmpresaActiva::class]);
