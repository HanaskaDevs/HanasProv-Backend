<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calificación de la Ficha de Proveedor por parte de un Admin/Sistemas:
 * "visto" (Aprobado) = 100, "X" (Rechazado) = 0. El puntaje numérico se
 * deriva de Estado_Calificacion_Ficha (no se guarda un número aparte),
 * mismo patrón de nombres que Producto.Estado_Calificacion /
 * Comentario_Calificacion, para que sea consistente en todo el sistema.
 * NULL = todavía no calificada (Pendiente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->string('Estado_Calificacion_Ficha', 20)->nullable()->after('Fecha_Registro_Documentacion');
            $table->text('Comentario_Calificacion_Ficha')->nullable()->after('Estado_Calificacion_Ficha');
            $table->unsignedBigInteger('Calificado_Por_Ficha')->nullable()->after('Comentario_Calificacion_Ficha');
            $table->dateTime('Fecha_Calificacion_Ficha')->nullable()->after('Calificado_Por_Ficha');
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn([
                'Estado_Calificacion_Ficha',
                'Comentario_Calificacion_Ficha',
                'Calificado_Por_Ficha',
                'Fecha_Calificacion_Ficha',
            ]);
        });
    }
};