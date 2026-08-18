<?php

use App\Modules\Auditorias\Http\Controllers\AuditoriaController;
use App\Modules\Auditorias\Http\Controllers\CalificacionRecepcionController;
use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use Illuminate\Support\Facades\Route;

Route::prefix('auditorias')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/tipos', [AuditoriaController::class, 'tiposAuditoria']);
        Route::get('/proveedores', [AuditoriaController::class, 'proveedores']);
        Route::post('/iniciar', [AuditoriaController::class, 'iniciar']);
        Route::get('/{auditoria}', [AuditoriaController::class, 'mostrar']);
        Route::post('/{auditoria}/respuestas', [AuditoriaController::class, 'guardarRespuesta']);
        Route::post('/{auditoria}/finalizar', [AuditoriaController::class, 'finalizar']);
    });

/**
 * Calificación de Recepciones (FGH04.15.05-1) -> vive en el módulo de
 * Auditorías porque es donde el negocio la ubica, pero con sus propias
 * tablas y su propio service (ver la migración para el porqué).
 *
 * Las rutas con segmento fijo (parametros / proveedores / historial /
 * iniciar) van ANTES de /{calificacion}: si no, "parametros" entraría por
 * el comodín y terminaría buscando una calificación con ese id.
 */
Route::prefix('calificacion-recepciones')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/parametros', [CalificacionRecepcionController::class, 'parametros']);
        Route::get('/proveedores', [CalificacionRecepcionController::class, 'proveedores']);
        Route::get('/historial', [CalificacionRecepcionController::class, 'historial']);
        Route::post('/iniciar', [CalificacionRecepcionController::class, 'iniciar']);

        Route::get('/{calificacion}', [CalificacionRecepcionController::class, 'mostrar']);
        Route::put('/{calificacion}/cabecera', [CalificacionRecepcionController::class, 'actualizarCabecera']);
        Route::post('/{calificacion}/respuestas', [CalificacionRecepcionController::class, 'guardarRespuesta']);
        Route::post('/{calificacion}/finalizar', [CalificacionRecepcionController::class, 'finalizar']);
    });
