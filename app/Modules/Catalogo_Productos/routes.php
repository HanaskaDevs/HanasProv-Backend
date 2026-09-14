<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Catalogo_Productos\Http\Controllers\CatalogoProductoController;
use Illuminate\Support\Facades\Route;

/**
 * Catálogo de Productos (interno). Todo el grupo pasa por EmpresaActiva
 * porque el catálogo es SIEMPRE de la empresa activa de la sesión.
 *
 * El gate de roles (Sistemas / Admin / Compras) NO está acá sino dentro
 * del Service (verificarAcceso), igual que en el resto de los módulos
 * -> así ningún endpoint nuevo puede quedar sin control por olvidarse de
 * ponerlo en la ruta.
 */
Route::prefix('catalogo-productos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [CatalogoProductoController::class, 'index']);
        Route::get('/resumen', [CatalogoProductoController::class, 'resumen']);
        Route::get('/exportar', [CatalogoProductoController::class, 'exportar']);

        // Carga masiva de Bc_Nro_Producto en dos pasos: primero validar
        // (devuelve el reporte sin guardar), después importar.
        Route::post('/importar/validar', [CatalogoProductoController::class, 'validarImportacion']);
        Route::post('/importar', [CatalogoProductoController::class, 'importar']);
    });