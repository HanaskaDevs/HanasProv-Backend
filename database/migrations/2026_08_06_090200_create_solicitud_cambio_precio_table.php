<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cada solicitud de cambio de precio hecha por un
 * proveedor ya Aprobado sobre uno de sus productos. Mientras
 * Estado = 'Pendiente', el producto queda con Precio_En_Revision = 1
 * (ver migración add_precio_en_revision_to_producto_table) y el precio
 * en la tabla Producto NO se toca todavía -> se actualiza recién al
 * aprobar. Si se rechaza, Producto.Precio nunca cambió, así que no
 * hace falta "revertir" nada ahí, solo cerrar la solicitud.
 *
 * Mismo patrón que Historial_Estado_Proveedor: guarda quién solicitó,
 * quién resolvió y cuándo, para trazabilidad completa del cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Solicitud_Cambio_Precio', function (Blueprint $table) {
            $table->id('Id_Solicitud_Cambio_Precio');
            // unsignedInteger (no unsignedBigInteger): Producto.Id_Producto
            // y Usuario.Id_Usuario son INT en la base real (creadas fuera
            // de Artisan) -> un tipo distinto en la FK revienta el ALTER
            // TABLE en SQL Server ("no coincide el tipo de datos").
            $table->unsignedInteger('Id_Producto');
            $table->decimal('Precio_Anterior', 12, 2);
            $table->decimal('Precio_Nuevo', 12, 2);
            // Pendiente | Aprobado | Rechazado
            $table->string('Estado', 20)->default('Pendiente');
            $table->unsignedInteger('Solicitado_Por')->nullable();
            $table->dateTime('Fecha_Solicitud')->useCurrent();
            $table->unsignedInteger('Resuelto_Por')->nullable();
            $table->dateTime('Fecha_Resolucion')->nullable();
            $table->string('Comentario_Resolucion', 300)->nullable();

            $table->foreign('Id_Producto', 'FK_SolicitudCambioPrecio_Producto')
                ->references('Id_Producto')->on('Producto');
            $table->foreign('Solicitado_Por', 'FK_SolicitudCambioPrecio_Solicitante')
                ->references('Id_Usuario')->on('Usuario');
            $table->foreign('Resuelto_Por', 'FK_SolicitudCambioPrecio_Resolutor')
                ->references('Id_Usuario')->on('Usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Solicitud_Cambio_Precio');
    }
};
