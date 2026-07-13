<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Pedidos\Http\Controllers\PedidoController;
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