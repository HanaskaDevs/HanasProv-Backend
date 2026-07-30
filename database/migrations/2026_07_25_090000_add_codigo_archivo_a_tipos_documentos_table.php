<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código corto (ej. "FT", "AL", "IESS") usado para nombrar el archivo
 * físico en disco -> separado de Carpeta_Slug a propósito: Carpeta_Slug
 * es para el NOMBRE DE CARPETA (legible, tipo "ficha-tecnica"),
 * Codigo_Archivo es para el PREFIJO DEL ARCHIVO (corto, tipo "FT").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Tipo_Documento', function (Blueprint $table) {
            $table->string('Codigo_Archivo', 15)->nullable()->after('Carpeta_Slug');
        });

        Schema::table('Tipo_Documento_Producto', function (Blueprint $table) {
            $table->string('Codigo_Archivo', 15)->nullable()->after('Carpeta_Slug');
        });
    }

    public function down(): void
    {
        Schema::table('Tipo_Documento', function (Blueprint $table) {
            $table->dropColumn('Codigo_Archivo');
        });

        Schema::table('Tipo_Documento_Producto', function (Blueprint $table) {
            $table->dropColumn('Codigo_Archivo');
        });
    }
};