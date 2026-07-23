<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calificación de la Ficha de Proveedor CAMPO POR CAMPO (reemplaza el
 * enfoque anterior de calificar toda la ficha como un solo bloque, que
 * quedaba en Proveedor.Estado_Calificacion_Ficha -> esas columnas se
 * dejan sin usar, no se borran, para no perder el historial ya cargado).
 *
 * Nombre_Campo es el nombre del campo del formulario (ej. "telefono",
 * "direccion") o de una sección completa para las que no aplican campo
 * por campo ("clase_proveedor", "categoria_productos").
 *
 * Un registro por (Id_Proveedor, Nombre_Campo) -> al recalificar un
 * campo se actualiza el mismo registro (upsert), no se acumulan
 * historiales por campo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Calificacion_Campo_Ficha', function (Blueprint $table) {
            $table->id('Id_Calificacion_Campo');
            $table->unsignedBigInteger('Id_Proveedor');
            $table->string('Nombre_Campo', 60);
            $table->string('Estado', 20); // Aprobado | Rechazado
            $table->text('Comentario')->nullable();
            $table->unsignedBigInteger('Calificado_Por');
            $table->dateTime('Fecha_Calificacion');

            $table->unique(['Id_Proveedor', 'Nombre_Campo']);
            $table->foreign('Id_Proveedor')->references('Id_Proveedor')->on('Proveedor')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Calificacion_Campo_Ficha');
    }
};