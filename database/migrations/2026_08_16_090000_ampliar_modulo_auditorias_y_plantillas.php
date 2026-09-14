<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambios de ESQUEMA para 2 pedidos de Isak (agosto 2026):
 *
 * 1) Tipo_Documento.Ruta_Plantilla: ruta (relativa al disco 'local',
 *    carpeta storage/app/plantillas) del archivo .docx en blanco que el
 *    proveedor puede descargar, llenar y volver a subir. Nullable ->
 *    la mayoría de los tipos de documento no tienen plantilla (ej. RUC,
 *    LUAE, permisos), solo "Check list autoevaluación de proveedores" y
 *    "Carta de Garantia" por ahora.
 *
 * 2) Tipo_Auditoria_Clase: pivote nuevo "esta Clase de Proveedor
 *    corresponde a este Tipo_Auditoria" -> se usa para sugerir/filtrar
 *    automáticamente el tipo de auditoría correcto según la(s) clase(s)
 *    del proveedor elegido en el wizard de auditorías. No existía
 *    ninguna relación entre Tipo_Auditoria y Clase_Proveedor hasta
 *    ahora (los 2 tipos viejos, "Proveedores" y "Proveedores
 *    Mataderos", no se relacionaban con nada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Tipo_Documento', function (Blueprint $table) {
            $table->string('Ruta_Plantilla', 300)->nullable()->after('Codigo_Archivo');
        });

        Schema::create('Tipo_Auditoria_Clase', function (Blueprint $table) {
            $table->id('Id_Tipo_Auditoria_Clase');
            $table->unsignedBigInteger('Id_Tipo_Auditoria');
            // Clase_Proveedor.Id_Clase_Proveedor es INT (no BIGINT) en la
            // base real -> unsignedInteger acá, si no SQL Server rechaza
            // la FK por tipos incompatibles (mismo criterio ya usado en
            // Tipo_Documento_Clase_Excluida).
            $table->unsignedInteger('Id_Clase_Proveedor');
            $table->boolean('Activo')->default(true);

            $table->foreign('Id_Tipo_Auditoria', 'FK_TipoAuditoriaClase_TipoAuditoria')
                ->references('Id_Tipo_Auditoria')->on('Tipo_Auditoria');
            $table->foreign('Id_Clase_Proveedor', 'FK_TipoAuditoriaClase_ClaseProveedor')
                ->references('Id_Clase_Proveedor')->on('Clase_Proveedor');
            $table->unique(['Id_Tipo_Auditoria', 'Id_Clase_Proveedor'], 'uq_tipo_auditoria_clase');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Tipo_Auditoria_Clase');

        Schema::table('Tipo_Documento', function (Blueprint $table) {
            $table->dropColumn('Ruta_Plantilla');
        });
    }
};
