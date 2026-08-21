<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Horarios_Entrega\Http\Controllers\HorarioEntregaController;
use Illuminate\Support\Facades\Route;

/**
 * Calendario de Horarios de Entrega de Proveedores (Perecibles / No
 * Perecibles / Fruver). Ver el catálogo con GET /horarios-entrega es para
 * Sistemas, Admin, Compras y Calidad; el resto de verbos (crear/editar/
 * borrar) es solo Sistemas/Admin -> el service es el que corta el acceso,
 * estas rutas no filtran por rol.
 *
 * '/proveedores' y '/hoy' van ANTES de rutas con {horario} (no aplica acá
 * porque no hay comodín de un solo segmento después del prefijo, pero se
 * mantienen agrupadas arriba por legibilidad, mismo criterio que
 * Auditorías/routes.php).
 *
 * '/hoy' es el seguimiento EN VIVO de hoy (Modo TV y la pantalla de
 * Guardia/Compras); marcar-arribo/marcar-entregado son los 2 eventos que
 * se marcan a mano (ver HorarioEntregaService para el resto de los
 * estados, que se calculan solos).
 */
Route::prefix('horarios-entrega')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [HorarioEntregaController::class, 'index']);
        Route::get('/proveedores', [HorarioEntregaController::class, 'proveedores']);
        Route::get('/hoy', [HorarioEntregaController::class, 'hoy']);
        Route::post('/', [HorarioEntregaController::class, 'store']);
        Route::put('/{horario}', [HorarioEntregaController::class, 'update']);
        Route::delete('/{horario}', [HorarioEntregaController::class, 'destroy']);
        Route::post('/{horario}/marcar-arribo', [HorarioEntregaController::class, 'marcarArribo']);
        Route::post('/{horario}/marcar-entregado', [HorarioEntregaController::class, 'marcarEntregado']);
    });
