<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Registrar calificación de productos" (admin): una vez que calificó
 * TODOS los productos actualmente en revisión (Bloqueado=1, sin importar
 * de qué lote sean), confirma y la sección queda de solo lectura hasta
 * que se reabra (misma idea que Fecha_Registro_Calificacion_Documentos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dateTime('Fecha_Registro_Calificacion_Productos')->nullable()->after('Fecha_Registro_Calificacion_Documentos');
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn('Fecha_Registro_Calificacion_Productos');
        });
    }
};