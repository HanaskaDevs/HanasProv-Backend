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
 * '/mios', '/proveedores', '/hoy' y '/aprobaciones' van ANTES de rutas con
 * {horario} por legibilidad, mismo criterio que Auditorías/routes.php.
 *
 * '/hoy' es el seguimiento EN VIVO de hoy (Modo TV y la pantalla de
 * Guardia/Calidad); marcar-arribo/solicitar-aprobacion son las 2 acciones
 * que dispara una persona -> En_Recepcion/Recibido ya NO se marcan a mano,
 * los pone SincronizarEstadosSighCommand leyendo SIGH (ver
 * HorarioEntregaService).
 *
 * '/aprobaciones' es la bandeja de Calidad para resolver solicitudes de
 * arribo tardío (Rechazado -> pidió aprobación).
 */
Route::prefix('horarios-entrega')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/', [HorarioEntregaController::class, 'index']);
        Route::get('/mios', [HorarioEntregaController::class, 'mios']);
        Route::get('/proveedores', [HorarioEntregaController::class, 'proveedores']);
        Route::get('/hoy', [HorarioEntregaController::class, 'hoy']);
        Route::get('/aprobaciones', [HorarioEntregaController::class, 'solicitudesPendientes']);
        Route::post('/aprobaciones/{solicitud}/aprobar', [HorarioEntregaController::class, 'aprobarSolicitud']);
        Route::post('/aprobaciones/{solicitud}/rechazar', [HorarioEntregaController::class, 'rechazarSolicitud']);
        Route::post('/', [HorarioEntregaController::class, 'store']);
        Route::put('/{horario}', [HorarioEntregaController::class, 'update']);
        Route::delete('/{horario}', [HorarioEntregaController::class, 'destroy']);
        Route::get('/{horario}/pedidos-del-dia', [HorarioEntregaController::class, 'pedidosDelDia']);
        Route::post('/{horario}/marcar-arribo', [HorarioEntregaController::class, 'marcarArribo']);
        Route::post('/{horario}/solicitar-aprobacion', [HorarioEntregaController::class, 'solicitarAprobacion']);
    });
