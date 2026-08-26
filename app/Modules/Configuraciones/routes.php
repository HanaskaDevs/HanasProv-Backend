<?php

use App\Modules\Auth\Http\Middleware\EmpresaActiva;
use App\Modules\Configuraciones\Http\Controllers\BotReglaController;
use App\Modules\Configuraciones\Http\Controllers\GuiaPasoController;
use App\Modules\Configuraciones\Http\Controllers\HomeSlideController;
use App\Modules\Configuraciones\Http\Controllers\LoginImagenController;
use App\Modules\Configuraciones\Http\Controllers\MediaStreamController;
use App\Modules\Configuraciones\Http\Controllers\PoliticaController;
use App\Modules\Configuraciones\Http\Controllers\PublicConfigController;
use App\Modules\Configuraciones\Http\Controllers\SuspensionDocumentosController;
use Illuminate\Support\Facades\Route;

/*
 * Los archivos del disco 'multimedia' (videos del home, imagen del login).
 *
 * PÚBLICA a propósito: son el fondo de la landing y del login, se piden antes
 * de que exista sesión.
 *
 * POR QUÉ PASAR POR PHP EN VEZ DE SERVIRLOS ESTÁTICOS. Hay un symlink
 * public/media -> el repositorio, y hasta ahora los videos se bajaban por ahí.
 * El problema: 'php artisan serve' sirve los estáticos SIN ningún header de
 * caché, así que el navegador volvía a bajar los ~450 KB de cada video en cada
 * visita a la landing, aunque el archivo no hubiera cambiado nunca. Por acá
 * salen con 'immutable' a un año (ver MediaStreamController), y de paso con
 * soporte de Range, que el servidor embebido tampoco da para estáticos.
 *
 * Es seguro cachear tan agresivo porque subir un archivo nuevo desde
 * Configuraciones genera un NOMBRE nuevo (ver ConfiguracionService), no
 * sobrescribe el anterior: una URL nunca cambia de contenido.
 *
 * El symlink se deja en su lugar: las URLs viejas /media/... que puedan estar
 * cacheadas o guardadas en algún registro antiguo siguen funcionando.
 *
 * where('ruta', '.*') porque la ruta trae barra ('home/archivo.mp4') y sin eso
 * el parámetro corta en el primer segmento.
 */
Route::get('/media/{ruta}', [MediaStreamController::class, 'show'])
    ->where('ruta', '.*')
    ->name('media.show');

// Públicas: sin autenticación, consumidas por Landing/Login/Tour antes de loguearse.
Route::prefix('public-config')->group(function () {
    Route::get('/home-slides', [PublicConfigController::class, 'homeSlides']);
    Route::get('/login-imagen', [PublicConfigController::class, 'loginImagen']);
    Route::get('/guia-pasos', [PublicConfigController::class, 'guiaPasos']);
});

// Sección "Políticas" dentro de la plataforma: cualquier usuario logueado
// (proveedor o interno, cualquier rol), solo lectura de las políticas activas.
Route::middleware(['auth:sanctum', EmpresaActiva::class])
    ->get('/politicas', [PoliticaController::class, 'verActivas']);

// Administración: solo Sistemas (la verificación real ocurre en el service).
Route::prefix('configuraciones')
    ->middleware(['auth:sanctum', EmpresaActiva::class])
    ->group(function () {
        Route::get('/home-slides', [HomeSlideController::class, 'index']);
        Route::post('/home-slides', [HomeSlideController::class, 'store']);
        Route::put('/home-slides/{slide}', [HomeSlideController::class, 'update']);
        Route::delete('/home-slides/{slide}', [HomeSlideController::class, 'destroy']);

        Route::get('/login-imagen', [LoginImagenController::class, 'show']);
        Route::post('/login-imagen', [LoginImagenController::class, 'update']);

        Route::get('/bot-reglas', [BotReglaController::class, 'index']);
        Route::post('/bot-reglas', [BotReglaController::class, 'store']);
        Route::put('/bot-reglas/{regla}', [BotReglaController::class, 'update']);
        Route::delete('/bot-reglas/{regla}', [BotReglaController::class, 'destroy']);

        Route::get('/guia-pasos', [GuiaPasoController::class, 'index']);
        Route::post('/guia-pasos', [GuiaPasoController::class, 'store']);
        Route::put('/guia-pasos/{paso}', [GuiaPasoController::class, 'update']);
        Route::delete('/guia-pasos/{paso}', [GuiaPasoController::class, 'destroy']);

        Route::get('/politicas', [PoliticaController::class, 'index']);
        Route::post('/politicas', [PoliticaController::class, 'store']);
        Route::put('/politicas/{politica}', [PoliticaController::class, 'update']);
        Route::delete('/politicas/{politica}', [PoliticaController::class, 'destroy']);
        Route::post('/politicas/extraer-pdf', [PoliticaController::class, 'extraerTextoPdf']);

        // Interruptor de la suspensión automática por documentos vencidos.
        Route::get('/suspension-documentos', [SuspensionDocumentosController::class, 'show']);
        Route::put('/suspension-documentos', [SuspensionDocumentosController::class, 'update']);
    });