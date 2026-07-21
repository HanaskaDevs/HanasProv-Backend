<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * true = el admin rechazó al menos un documento y el proveedor todavía
 * no confirmó que ya terminó de corregir -> MIENTRAS esté en true, los
 * documentos no-Aprobados quedan editables aunque la documentación ya
 * esté "registrada" (para poder corregir, y para poder corregir un
 * error propio al reemplazar sin que el admin tenga que rechazar de
 * nuevo). Se apaga cuando el proveedor confirma con "Registrar
 * documentación actualizada" (exige que ya no quede ningún documento
 * en estado Rechazado) -> ahí vuelve a quedar todo bloqueado hasta que
 * el admin dé su retroalimentación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->boolean('Correcciones_Pendientes')->default(false)->after('Fecha_Registro_Calificacion_Documentos');
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn('Correcciones_Pendientes');
        });
    }
};