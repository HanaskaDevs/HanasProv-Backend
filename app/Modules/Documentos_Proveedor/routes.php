<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Documentos_Proveedor\Http\Controllers\DocumentoProveedorController;
use Illuminate\Support\Facades\Route;

Route::prefix('mi-documentos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [DocumentoProveedorController::class, 'index']);
        Route::post('/registrar', [DocumentoProveedorController::class, 'registrar']);
        Route::post('/documento/{documentoProveedor}/reemplazar', [DocumentoProveedorController::class, 'reemplazar']);
        Route::delete('/documento/{documentoProveedor}', [DocumentoProveedorController::class, 'borrar']);
        Route::post('/{tipoDocumento}', [DocumentoProveedorController::class, 'subir']);
        Route::get('/{documentoProveedor}/descargar', [DocumentoProveedorController::class, 'descargar']);
    });