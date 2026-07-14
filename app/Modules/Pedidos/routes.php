<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Pedidos\Http\Controllers\PedidoController;
use App\Modules\Pedidos\Http\Controllers\PedidoInternoController;
use Illuminate\Support\Facades\Route;

Route::prefix('pedidos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/abiertos', [PedidoController::class, 'abiertos']);
        Route::get('/cerrados', [PedidoController::class, 'cerrados']);
        Route::post('/actualizar', [PedidoController::class, 'actualizar']);
        Route::patch('/{pedido}/cerrar', [PedidoController::class, 'cerrar']);
        Route::post('/descargar-pdf', [PedidoController::class, 'descargarPdf']);
    });

Route::prefix('pedidos-internos')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [PedidoInternoController::class, 'index']);
        Route::get('/{pedido}', [PedidoInternoController::class, 'show']);
        Route::post('/{pedido}/recepciones', [PedidoInternoController::class, 'registrarRecepcion']);
        Route::put('/recepciones-detalle/{detalle}', [PedidoInternoController::class, 'actualizarDetalle']);
        Route::patch('/{pedido}/cerrar', [PedidoInternoController::class, 'cerrarPedido']);
        Route::get('/recepciones-imagen/{imagen}/ver', [PedidoInternoController::class, 'verImagen']);
    });