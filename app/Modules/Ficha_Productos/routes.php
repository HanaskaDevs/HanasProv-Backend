<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Ficha_Productos\Http\Controllers\ProductoController;
use App\Modules\Ficha_Productos\Http\Controllers\ProductosDeProveedorController;
use App\Modules\Ficha_Productos\Http\Controllers\SolicitudCambioPrecioController;
use App\Modules\Ficha_Productos\Http\Controllers\UnidadPresentacionController;
use Illuminate\Support\Facades\Route;

Route::prefix('mis-productos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [ProductoController::class, 'index']);
        Route::post('/', [ProductoController::class, 'store']);
        Route::get('/unidades-presentacion', [UnidadPresentacionController::class, 'index']);
        Route::get('/tipos-documento', [ProductoController::class, 'tiposDocumento']);
        Route::get('/documentos/{documentoProducto}/ver', [ProductoController::class, 'descargarDocumento']);
        Route::post('/{producto}/documentos/{tipoDocumento}', [ProductoController::class, 'subirDocumento']);
        // Editar un producto propio (nombre, unidad, medidas, grupos...).
        // Va después de las rutas literales para que '{producto}' no se
        // coma un 'registrar' o un 'resumen-registro'.
        Route::put('/{producto}', [ProductoController::class, 'update']);
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

/**
 * FICHA DE PRODUCTOS DE UN PROVEEDOR, PARA PERSONAL INTERNO (Compras,
 * Admin, Sistemas). El comprador elige un proveedor y trabaja su catálogo
 * completo: crear, editar, subir documentos y mandar a aprobar.
 *
 * PREFIJO PROPIO Y NO '/proveedores/{proveedor}/productos' a propósito:
 * el prefijo 'proveedores' ya lo usa el módulo Proveedores con un
 * '/{proveedor}' comodín y sus rutas de calificación. Meter otra familia
 * de rutas ahí adentro es exactamente el choque que documenta
 * Catalogos/routes.php (dos grupos peleándose el mismo path), así que se
 * evita de entrada.
 *
 * ORDEN DE LAS RUTAS: primero todo lo que tiene un segmento LITERAL
 * ('proveedores', 'masivo', 'registrar', 'documentos'...), y recién al
 * final los comodines. Al revés, '/{proveedor}/{producto}' se tragaría
 * '/{proveedor}/registrar' tratando "registrar" como un id de producto.
 */
Route::prefix('productos-proveedor')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        // Selector: qué proveedores hay y cuántos productos tiene cada uno.
        Route::get('/proveedores', [ProductosDeProveedorController::class, 'proveedores']);

        Route::get('/{proveedor}/resumen-registro', [ProductosDeProveedorController::class, 'resumenRegistro']);
        Route::post('/{proveedor}/registrar', [ProductosDeProveedorController::class, 'registrar']);
        Route::delete('/{proveedor}/masivo', [ProductosDeProveedorController::class, 'destroyMasivo']);

        Route::get('/{proveedor}/documentos/{documentoProducto}/ver', [ProductosDeProveedorController::class, 'descargarDocumento']);
        Route::delete('/{proveedor}/documentos/{documentoProducto}', [ProductosDeProveedorController::class, 'destroyDocumento']);

        Route::post('/{proveedor}/{producto}/documentos/{tipoDocumento}', [ProductosDeProveedorController::class, 'subirDocumento']);
        Route::post('/{proveedor}/{producto}/confirmar-correccion', [ProductosDeProveedorController::class, 'confirmarCorreccionProducto']);

        Route::get('/{proveedor}', [ProductosDeProveedorController::class, 'index']);
        Route::post('/{proveedor}', [ProductosDeProveedorController::class, 'store']);
        Route::put('/{proveedor}/{producto}', [ProductosDeProveedorController::class, 'update']);
        Route::delete('/{proveedor}/{producto}', [ProductosDeProveedorController::class, 'destroy']);
    });
