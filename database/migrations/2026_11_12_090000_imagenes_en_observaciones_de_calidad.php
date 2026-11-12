<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imágenes en las observaciones de Calidad (pedido del usuario, 12-nov-2026).
 *
 * En "Calificación de auditorías" y "Calificación de recepciones" cada
 * pregunta ya permitía escribir una observación; ahora además se pueden
 * adjuntar hasta 2 fotos por pregunta (el tope vive en el código, ver
 * ImagenObservacionService::MAX_IMAGENES, para no tener que migrar si
 * mañana cambia).
 *
 * DOS TABLAS Y NO UNA POLIMÓRFICA: auditorías y recepciones son dos
 * módulos con tablas, services y formularios propios (ver la migración
 * 2026_08_18 del módulo de recepciones), y una columna "tipo" + id suelto
 * no podría tener FK real. Con dos tablas, la base garantiza que una
 * imagen cuelgue de una respuesta que existe, y borrar la calificación se
 * lleva sus imágenes por cascada.
 *
 * EL ARCHIVO FÍSICO NO SE GUARDA ACÁ: igual que Reclamo_Mensaje_Imagen,
 * estas tablas solo enlazan la respuesta con una fila de `Archivo`, que es
 * la que tiene ruta, hash, tamaño y quién lo subió. El binario vive en el
 * repositorio de archivos (disco 'auditorias' -> /var/repositorio/auditorias).
 *
 * TIPOS: Archivo.Id_Archivo es `int` en la base real, mientras que las dos
 * tablas de respuestas usan `bigint`. SQL Server exige que la columna de
 * una FK tenga EXACTAMENTE el mismo tipo que la referenciada, así que acá
 * van mezclados a propósito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Auditoria_Respuesta_Imagen', function (Blueprint $table) {
            $table->id('Id_Auditoria_Respuesta_Imagen');
            $table->unsignedBigInteger('Id_Auditoria_Respuesta');
            $table->unsignedInteger('Id_Archivo');
            $table->boolean('Activo')->default(true);

            $table->foreign('Id_Auditoria_Respuesta', 'FK_AuditoriaRespImagen_Respuesta')
                ->references('Id_Auditoria_Respuesta')->on('Auditoria_Respuesta')
                ->cascadeOnDelete();
            $table->foreign('Id_Archivo', 'FK_AuditoriaRespImagen_Archivo')
                ->references('Id_Archivo')->on('Archivo');

            $table->index('Id_Auditoria_Respuesta', 'IX_AuditoriaRespImagen_Respuesta');
        });

        Schema::create('Calificacion_Recepcion_Respuesta_Imagen', function (Blueprint $table) {
            $table->id('Id_Calificacion_Recepcion_Respuesta_Imagen');
            $table->unsignedBigInteger('Id_Calificacion_Recepcion_Respuesta');
            $table->unsignedInteger('Id_Archivo');
            $table->boolean('Activo')->default(true);

            $table->foreign('Id_Calificacion_Recepcion_Respuesta', 'FK_CalifRecepRespImagen_Respuesta')
                ->references('Id_Calificacion_Recepcion_Respuesta')->on('Calificacion_Recepcion_Respuesta')
                ->cascadeOnDelete();
            $table->foreign('Id_Archivo', 'FK_CalifRecepRespImagen_Archivo')
                ->references('Id_Archivo')->on('Archivo');

            $table->index('Id_Calificacion_Recepcion_Respuesta', 'IX_CalifRecepRespImagen_Respuesta');
        });
    }

    public function down(): void
    {
        // Solo se borran las filas de enlace: las de `Archivo` y los
        // binarios del repositorio quedan, igual que cuando se borra una
        // imagen desde el portal (ver ImagenObservacionService::eliminar).
        Schema::dropIfExists('Calificacion_Recepcion_Respuesta_Imagen');
        Schema::dropIfExists('Auditoria_Respuesta_Imagen');
    }
};
