<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Registrar documentación": una vez que el proveedor confirma su
 * checklist de documentos, queda bloqueado (no puede subir/reemplazar
 * nada más), solo puede ver lo ya cargado. Se guarda como fecha en vez
 * de un boolean simple para tener también el dato de CUÁNDO se registró.
 * NULL = todavía no ha registrado -> puede seguir subiendo/reemplazando.
 *
 * Sigue el mismo patrón que add_ficha_fields_to_proveedor_table: esta
 * migration se aplica manualmente en el servidor SQL Server vía script,
 * acá queda solo como historial de versionado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dateTime('Fecha_Registro_Documentacion')->nullable()->after('Porcentaje_Completado_Ficha');
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn('Fecha_Registro_Documentacion');
        });
    }
};