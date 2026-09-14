<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mismo mecanismo que Correcciones_Pendientes (Documentos), pero para
 * Productos: true = el admin rechazó al menos un producto y el
 * proveedor todavía no confirmó haber terminado de corregir. Es un
 * campo APARTE del de Documentos a propósito -> son dominios distintos,
 * no tendría sentido que rechazar un documento de la ficha bloqueara (o
 * desbloqueara) la corrección de productos, y viceversa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->boolean('Correcciones_Pendientes_Productos')->default(false)->after('Fecha_Registro_Calificacion_Productos');
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn('Correcciones_Pendientes_Productos');
        });
    }
};