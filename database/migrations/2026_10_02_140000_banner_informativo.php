<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banner informativo: el aviso que Sistemas puede encender para que lo vea
 * TODO el que inicie sesión (pedido del usuario, 02-oct-2026).
 *
 * UNA TABLA DE PIEZAS Y NO UNA FILA ÚNICA. El pedido incluye "un video, o
 * varias imágenes, o una sola imagen con información": eso es una lista,
 * no un registro. Con una tabla, el banner puede ser un carrusel de tres
 * imágenes hoy y un video mañana sin tocar el esquema. Es la misma forma
 * que ya tiene Home_Slide, que resuelve exactamente el mismo problema en
 * la landing.
 *
 * EL INTERRUPTOR Y LOS TEXTOS GENERALES NO VAN ACÁ: viven en la tabla
 * Configuracion (claves banner_informativo_*), que es donde el proyecto ya
 * guarda los ajustes sueltos de una sola instancia. Meterlos acá obligaría
 * a inventar una fila especial que no es una pieza del banner.
 *
 * Activo por pieza (además del interruptor general): permite dejar una
 * imagen preparada sin mostrarla todavía, o sacar una del carrusel sin
 * borrar el archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Banner_Informativo', function (Blueprint $table) {
            $table->increments('Id_Banner_Informativo');
            $table->integer('Orden')->default(0);
            $table->string('Titulo', 200)->nullable();
            $table->string('Descripcion', 1000)->nullable();

            // Ruta RELATIVA dentro del disco 'multimedia', nunca la URL
            // absoluta: el host y el puerto cambian entre entornos y una
            // URL guardada queda rota para siempre (ver el comentario de
            // ConfiguracionService::guardarMediaPublica).
            $table->string('Ruta_Media', 300)->nullable();
            $table->string('Tipo_Media', 10)->nullable();

            $table->boolean('Activo')->default(true);
            $table->dateTime('Fecha_Creacion')->nullable();
            $table->integer('Creado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();
            $table->integer('Modificado_Por')->nullable();

            $table->index(['Activo', 'Orden'], 'IX_Banner_Informativo_Activo_Orden');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Banner_Informativo');
    }
};
