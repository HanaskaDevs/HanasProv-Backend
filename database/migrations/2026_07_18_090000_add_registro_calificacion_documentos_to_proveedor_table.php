<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Registrar calificación de documentos" (admin): una vez que calificó
 * TODOS los documentos cargados, confirma y la sección queda bloqueada
 * (de solo lectura) hasta que el proveedor corrija algún documento
 * rechazado -> eso reabre esto automáticamente (ver
 * DocumentoProveedorService::reemplazarDocumento).
 * Mismo patrón que Proveedor.Fecha_Registro_Documentacion (el registro
 * del PROVEEDOR), pero este es el lado del ADMIN calificando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dateTime('Fecha_Registro_Calificacion_Documentos')->nullable()->after('Fecha_Registro_Documentacion');
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn('Fecha_Registro_Calificacion_Documentos');
        });
    }
};