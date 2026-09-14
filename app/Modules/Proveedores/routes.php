<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Proveedores\Http\Controllers\CalificacionGlobalController;
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

        // Calificaciones globales de TODOS los proveedores de la empresa,
        // de una sola vez. Va antes de /{proveedor} para que el comodín no
        // se coma 'calificaciones-globales' tratándolo como un id.
        Route::get('/calificaciones-globales', [CalificacionGlobalController::class, 'lote']);

        // Calificación global (desempeño) de un proveedor, para el
        // personal interno.
        Route::get('/{proveedor}/calificacion-global', [CalificacionGlobalController::class, 'mostrar']);

        Route::get('/{proveedor}', [ProveedorController::class, 'show']);

        // Calificación (Admin/Sistemas) -> validado dentro del Service,
        // no solo por estar en este grupo de rutas.
        Route::get('/{proveedor}/ficha-calificacion', [CalificacionProveedorController::class, 'mostrarFicha']);
        Route::post('/{proveedor}/ficha-calificacion', [CalificacionProveedorController::class, 'calificarFichaGeneral']);
        Route::get('/{proveedor}/documentos-calificacion', [CalificacionProveedorController::class, 'mostrarDocumentos']);
        Route::post('/{proveedor}/documentos-calificacion/registrar', [CalificacionProveedorController::class, 'registrarCalificacionDocumentos']);
        Route::post('/documentos-calificacion/{documentoProveedor}', [CalificacionProveedorController::class, 'calificarDocumento']);
        Route::get('/documentos-calificacion/{documentoProveedor}/ver', [CalificacionProveedorController::class, 'verDocumento']);
        Route::get('/{proveedor}/productos-calificacion', [CalificacionProveedorController::class, 'mostrarProductos']);
        Route::post('/{proveedor}/productos-calificacion/registrar', [CalificacionProveedorController::class, 'registrarCalificacionProductos']);
        Route::post('/productos-calificacion/{producto}', [CalificacionProveedorController::class, 'calificarProducto']);
        Route::get('/productos-calificacion/documento/{documentoProducto}/ver', [CalificacionProveedorController::class, 'verDocumentoProducto']);
    });

// Ficha de Proveedor progresiva: exclusiva del propio usuario externo.
Route::prefix('mi-ficha')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [FichaProveedorController::class, 'show']);
        Route::put('/contactos', [FichaProveedorController::class, 'contactos']);
        Route::put('/seccion-1', [FichaProveedorController::class, 'seccion1']);
        Route::put('/seccion-2', [FichaProveedorController::class, 'seccion2']);
        Route::put('/seccion-3', [FichaProveedorController::class, 'seccion3']);
        // Cuenta bancaria: la declara el proveedor desde Documentación,
        // junto al PDF del certificado bancario, pero es dato de ficha ->
        // vive acá para reusar la resolución de "mi proveedor".
        Route::get('/cuenta-bancaria', [FichaProveedorController::class, 'cuentaBancaria']);
        Route::put('/cuenta-bancaria', [FichaProveedorController::class, 'guardarCuentaBancaria']);
    });

// Calificación global del propio proveedor: la ve en su pantalla de
// Calificación, arriba a la derecha, con el desglose desplegable.
Route::middleware(['auth:sanctum', EmpresaActiva::class])->group(function () {
    Route::get('/mi-calificacion-global', [CalificacionGlobalController::class, 'miCalificacion']);
    // Apaga el cartel de "ya es proveedor aprobado" para que no vuelva a salir.
    Route::post('/mi-calificacion-global/felicitacion-vista', [CalificacionGlobalController::class, 'marcarFelicitacionVista']);
});

// Catálogos globales (no dependen de empresa activa) usados en los
// multi-select de la Ficha de Proveedor.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/catalogos/clases-proveedor', [CatalogoController::class, 'clasesProveedor']);
    Route::get('/catalogos/categorias-producto', [CatalogoController::class, 'categoriasProducto']);
    Route::get('/catalogos/grupos-producto', [CatalogoController::class, 'gruposProducto']);
    // Clase de contribuyente (Sección 1) y bancos (cuenta bancaria).
    Route::get('/catalogos/grupos-impuesto', [CatalogoController::class, 'gruposImpuesto']);
    Route::get('/catalogos/bancos', [CatalogoController::class, 'bancos']);
});