<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Responsables\Http\Controllers\ResponsableController;
use Illuminate\Support\Facades\Route;

/*
 * A quién le escribe el proveedor cuando tiene una duda.
 *
 * La lectura la hace el PROVEEDOR desde su panel; la administración es de
 * Sistemas y el permiso lo valida el Service, no la ruta (mismo criterio
 * que el resto del proyecto).
 */
Route::middleware(['auth:sanctum', EmpresaActiva::class])
    ->get('/mi-responsable', [ResponsableController::class, 'miResponsable']);

Route::prefix('responsables')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        // Las rutas con segmento LITERAL van antes de los comodines, si no
        // '/{responsable}' se come 'asignaciones' tratándolo como un id.
        Route::get('/asignaciones', [ResponsableController::class, 'asignaciones']);
        Route::delete('/asignaciones/{asignacion}', [ResponsableController::class, 'eliminarAsignacion']);
        Route::post('/importar/validar', [ResponsableController::class, 'validarImportacion']);
        Route::post('/importar', [ResponsableController::class, 'importar']);

        Route::get('/', [ResponsableController::class, 'index']);
        Route::post('/', [ResponsableController::class, 'store']);
        Route::put('/{responsable}', [ResponsableController::class, 'update']);
        Route::delete('/{responsable}', [ResponsableController::class, 'destroy']);
    });
