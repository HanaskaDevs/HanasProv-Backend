<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calificación individual de cada documento cargado (independiente del
 * campo "Estado" que ya existe en esta tabla, que es del ciclo de vida
 * Vigente/Vencido, no de la revisión del admin). Mismo patrón de nombres
 * que Producto.Estado_Calificacion, para consistencia en todo el sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Documento_Proveedor', function (Blueprint $table) {
            $table->string('Estado_Calificacion', 20)->nullable()->after('Estado');
            $table->text('Comentario_Calificacion')->nullable()->after('Estado_Calificacion');
            $table->unsignedBigInteger('Calificado_Por')->nullable()->after('Comentario_Calificacion');
            $table->dateTime('Fecha_Calificacion')->nullable()->after('Calificado_Por');
        });
    }

    public function down(): void
    {
        Schema::table('Documento_Proveedor', function (Blueprint $table) {
            $table->dropColumn(['Estado_Calificacion', 'Comentario_Calificacion', 'Calificado_Por', 'Fecha_Calificacion']);
        });
    }
};