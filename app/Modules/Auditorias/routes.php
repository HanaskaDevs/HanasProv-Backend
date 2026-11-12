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
        Route::get('/resumen', [AuditoriaController::class, 'resumen']);
        // Segmento fijo: antes de /{auditoria}.
        Route::get('/reporte', [AuditoriaController::class, 'reporte']);
        Route::post('/iniciar', [AuditoriaController::class, 'iniciar']);

        // Fotos de la observación (12-nov-2026). Van ANTES de /{auditoria}
        // por el mismo motivo que /reporte: "imagenes" entraría por el
        // comodín y terminaría buscando una auditoría con ese id.
        Route::get('/imagenes/{imagen}/ver', [AuditoriaController::class, 'verImagen']);
        Route::delete('/imagenes/{imagen}', [AuditoriaController::class, 'eliminarImagen']);

        Route::get('/{auditoria}', [AuditoriaController::class, 'mostrar']);
        Route::post('/{auditoria}/imagenes', [AuditoriaController::class, 'subirImagen']);
        Route::post('/{auditoria}/respuestas', [AuditoriaController::class, 'guardarRespuesta']);
        Route::post('/{auditoria}/finalizar', [AuditoriaController::class, 'finalizar']);
    });

/**
 * Calificación de Recepciones (FGH04.15.05-1) -> vive en el módulo de
 * Auditorías porque es donde el negocio la ubica, pero con sus propias
 * tablas y su propio service (ver la migración para el porqué).
 *
 * Las rutas con segmento fijo (parametros / proveedores / historial /
 * reporte / iniciar) van ANTES de /{calificacion}: si no, "parametros" entraría por
 * el comodín y terminaría buscando una calificación con ese id.
 */
Route::prefix('calificacion-recepciones')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/parametros', [CalificacionRecepcionController::class, 'parametros']);
        Route::get('/proveedores', [CalificacionRecepcionController::class, 'proveedores']);
        Route::get('/historial', [CalificacionRecepcionController::class, 'historial']);
        Route::get('/reporte', [CalificacionRecepcionController::class, 'reporte']);
        Route::post('/iniciar', [CalificacionRecepcionController::class, 'iniciar']);

        // Fotos de la observación (12-nov-2026), también con segmento fijo.
        Route::get('/imagenes/{imagen}/ver', [CalificacionRecepcionController::class, 'verImagen']);
        Route::delete('/imagenes/{imagen}', [CalificacionRecepcionController::class, 'eliminarImagen']);

        Route::get('/{calificacion}', [CalificacionRecepcionController::class, 'mostrar']);
        Route::post('/{calificacion}/imagenes', [CalificacionRecepcionController::class, 'subirImagen']);
        Route::put('/{calificacion}/cabecera', [CalificacionRecepcionController::class, 'actualizarCabecera']);
        Route::post('/{calificacion}/respuestas', [CalificacionRecepcionController::class, 'guardarRespuesta']);
        Route::post('/{calificacion}/finalizar', [CalificacionRecepcionController::class, 'finalizar']);
    });
