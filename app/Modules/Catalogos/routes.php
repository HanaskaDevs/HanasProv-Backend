<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Catalogos\Http\Controllers\CategoriaProductoController;
use App\Modules\Catalogos\Http\Controllers\ClaseProveedorController;
use App\Modules\Catalogos\Http\Controllers\TipoDocumentoController;
use App\Modules\Catalogos\Http\Controllers\TipoDocumentoProductoController;
use App\Modules\Catalogos\Http\Controllers\UnidadPresentacionAdminController;
use Illuminate\Support\Facades\Route;

/**
 * CRUD de administración (solo Sistemas, verificado en cada controller
 * con esSistemasGlobal()) para los catálogos que antes solo se podían
 * cargar con SQL directo: Clase_Proveedor, Categoria_Producto,
 * Tipo_Documento, Tipo_Documento_Producto y Unidad_Presentacion.
 *
 * Los endpoints de solo lectura que YA existían para que un proveedor
 * vea estas listas (ej. GET /proveedores/clases, GET
 * /ficha-productos/unidades-presentacion) no se tocan, siguen en sus
 * módulos de siempre -> esto es exclusivamente para la gestión admin.
 */
Route::prefix('catalogos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::prefix('clases-proveedor')->group(function () {
            Route::get('/', [ClaseProveedorController::class, 'index']);
            Route::post('/', [ClaseProveedorController::class, 'store']);
            Route::put('/{clase}', [ClaseProveedorController::class, 'update']);
            Route::delete('/{clase}', [ClaseProveedorController::class, 'destroy']);
            Route::patch('/{clase}/activar', [ClaseProveedorController::class, 'activar']);
        });

        Route::prefix('categorias-producto')->group(function () {
            Route::get('/', [CategoriaProductoController::class, 'index']);
            Route::post('/', [CategoriaProductoController::class, 'store']);
            Route::put('/{categoria}', [CategoriaProductoController::class, 'update']);
            Route::delete('/{categoria}', [CategoriaProductoController::class, 'destroy']);
            Route::patch('/{categoria}/activar', [CategoriaProductoController::class, 'activar']);
        });

        Route::prefix('tipos-documento')->group(function () {
            Route::get('/', [TipoDocumentoController::class, 'index']);
            Route::post('/', [TipoDocumentoController::class, 'store']);
            Route::put('/{tipoDocumento}', [TipoDocumentoController::class, 'update']);
            Route::delete('/{tipoDocumento}', [TipoDocumentoController::class, 'destroy']);
            Route::patch('/{tipoDocumento}/activar', [TipoDocumentoController::class, 'activar']);
        });

        Route::prefix('tipos-documento-producto')->group(function () {
            Route::get('/', [TipoDocumentoProductoController::class, 'index']);
            Route::post('/', [TipoDocumentoProductoController::class, 'store']);
            Route::put('/{tipoDocumentoProducto}', [TipoDocumentoProductoController::class, 'update']);
            Route::delete('/{tipoDocumentoProducto}', [TipoDocumentoProductoController::class, 'destroy']);
            Route::patch('/{tipoDocumentoProducto}/activar', [TipoDocumentoProductoController::class, 'activar']);
        });

        Route::prefix('unidades-presentacion')->group(function () {
            Route::get('/', [UnidadPresentacionAdminController::class, 'index']);
            Route::post('/', [UnidadPresentacionAdminController::class, 'store']);
            Route::put('/{unidadPresentacion}', [UnidadPresentacionAdminController::class, 'update']);
            Route::delete('/{unidadPresentacion}', [UnidadPresentacionAdminController::class, 'destroy']);
            Route::patch('/{unidadPresentacion}/activar', [UnidadPresentacionAdminController::class, 'activar']);
        });
    });