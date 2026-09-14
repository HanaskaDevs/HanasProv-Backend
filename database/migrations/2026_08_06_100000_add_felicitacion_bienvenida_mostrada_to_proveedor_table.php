<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca si ya se le mostró al proveedor el mensaje proactivo de
 * felicitación de Hana (el bot) al quedar Aprobado -> sin este flag,
 * cada vez que el proveedor entra al portal habría que decidir "es la
 * primera vez que entra después de aprobarse" comparando fechas
 * (Fecha_Aprobacion vs Ultimo_Acceso), lo cual es frágil porque
 * Ultimo_Acceso se pisa en CADA login, incluido el que dispara esta
 * misma consulta. Con un flag booleano explícito, se marca una sola
 * vez la primera vez que se muestra y nunca más se repite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->boolean('Felicitacion_Bienvenida_Mostrada')->default(false)->after('Fecha_Aprobacion');
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn('Felicitacion_Bienvenida_Mostrada');
        });
    }
};
