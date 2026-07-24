<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subseccion es solo una etiqueta de agrupación visual dentro de una
 * sección (ej. "Corrales de Recepción" dentro de "Mantenimiento e
 * Instalaciones") -> NO tiene puntaje propio, no afecta ningún cálculo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Auditoria_Pregunta', function (Blueprint $table) {
            $table->string('Subseccion', 150)->nullable()->after('Id_Auditoria_Seccion');
        });
    }

    public function down(): void
    {
        Schema::table('Auditoria_Pregunta', function (Blueprint $table) {
            $table->dropColumn('Subseccion');
        });
    }
};