<?php

use App\Modules\Legal\Http\Controllers\SolicitudDerechosController;
use Illuminate\Support\Facades\Route;

/**
 * Formulario de atención de derechos (LOPDP).
 *
 * PÚBLICO, sin auth:sanctum, y tiene que serlo: el titular que quiere que
 * borren sus datos puede ser justamente alguien que ya no tiene cuenta, o a
 * quien se le desactivó. Exigir login para ejercer un derecho legal sería
 * negarlo en la práctica.
 *
 * Por lo mismo va con throttle explícito: es un endpoint anónimo que
 * dispara correo al Delegado de Protección de Datos, o sea el candidato
 * perfecto para inundar esa casilla. 3 envíos por IP cada 10 minutos deja
 * margen de sobra para corregir un dato y reenviar, y no para más.
 */
Route::middleware('throttle:3,10')
    ->post('/derechos-datos', [SolicitudDerechosController::class, 'enviar']);
