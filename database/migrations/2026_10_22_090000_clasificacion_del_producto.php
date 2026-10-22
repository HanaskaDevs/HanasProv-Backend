<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categoría de cada producto: Perecibles, No Perecibles o Fruver (pedido
 * del usuario, 22-oct-2026). Mismos valores que la clasificación del
 * calendario de horarios.
 *
 * POR QUÉ: en Fruver, la "Notificación sanitaria o Certificado de
 * Inscripción de alimentos" deja de ser obligatoria (ver
 * TipoDocumentoProducto::esObligatorioPara).
 *
 * NULLABLE a propósito: los productos que ya existían (847 activos al
 * escribir esto) quedan sin categoría y el portal se la pide al proveedor
 * cuando abre sus documentos. Sin categoría no se puede enviar a revisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->string('Clasificacion', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->dropColumn('Clasificacion');
        });
    }
};
