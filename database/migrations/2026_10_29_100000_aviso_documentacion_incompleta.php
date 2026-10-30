<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se le avisó por última vez al proveedor que tiene la documentación
 * incompleta (pedido del usuario, 29-oct-2026; ver
 * RecordatorioDocumentacionService). Sirve para no repetir el correo todos
 * los días: se reitera recién cuando pasa otra semana sin novedades.
 *
 * NULL = nunca se le avisó. Nada cambia para los proveedores existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dateTime('Fecha_Aviso_Documentacion_Incompleta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn('Fecha_Aviso_Documentacion_Incompleta');
        });
    }
};
