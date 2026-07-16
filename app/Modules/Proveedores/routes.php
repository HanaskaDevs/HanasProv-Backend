<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Proveedores\Http\Controllers\CalificacionProveedorController;
use App\Modules\Proveedores\Http\Controllers\CatalogoController;
use App\Modules\Proveedores\Http\Controllers\FichaProveedorController;
use App\Modules\Proveedores\Http\Controllers\ProveedorController;
use Illuminate\Support\Facades\Route;

Route::prefix('proveedores')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [ProveedorController::class, 'index']);
        Route::post('/', [ProveedorController::class, 'store']);
        Route::get('/{proveedor}', [ProveedorController::class, 'show']);

        // Calificación (Admin/Sistemas) -> validado dentro del Service,
        // no solo por estar en este grupo de rutas.
        Route::get('/{proveedor}/ficha-calificacion', [CalificacionProveedorController::class, 'mostrarFicha']);
        Route::post('/{proveedor}/ficha-calificacion', [CalificacionProveedorController::class, 'calificarFicha']);
        Route::get('/{proveedor}/documentos-calificacion', [CalificacionProveedorController::class, 'mostrarDocumentos']);
        Route::post('/documentos-calificacion/{documentoProveedor}', [CalificacionProveedorController::class, 'calificarDocumento']);
        Route::get('/documentos-calificacion/{documentoProveedor}/ver', [CalificacionProveedorController::class, 'verDocumento']);
    });

// Ficha de Proveedor progresiva: exclusiva del propio usuario externo.
Route::prefix('mi-ficha')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [FichaProveedorController::class, 'show']);
        Route::put('/seccion-1', [FichaProveedorController::class, 'seccion1']);
        Route::put('/seccion-2', [FichaProveedorController::class, 'seccion2']);
        Route::put('/seccion-3', [FichaProveedorController::class, 'seccion3']);
    });

// Catálogos globales (no dependen de empresa activa) usados en los
// multi-select de la Ficha de Proveedor.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/catalogos/clases-proveedor', [CatalogoController::class, 'clasesProveedor']);
    Route::get('/catalogos/categorias-producto', [CatalogoController::class, 'categoriasProducto']);
});