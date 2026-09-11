<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad del registro del proveedor en Business Central.
 *
 * - Nro_Proveedor_BC: el "No" que BC asignó (PROV-00000XX). Además de
 *   servir para soporte, actúa como GUARDA DE IDEMPOTENCIA: si ya tiene
 *   valor, no se vuelve a crear en BC (una recalificación o el comando
 *   de reconciliación lo duplicarían).
 * - Error_Posteo_BC: guarda el motivo del último intento fallido EN el
 *   proveedor, para que Sistemas vea desde el portal cuáles quedaron sin
 *   registrar, en vez de tener que leer laravel.log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->string('Nro_Proveedor_BC', 20)->nullable();
            $table->dateTime('Fecha_Posteo_BC')->nullable();
            $table->string('Error_Posteo_BC', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn(['Nro_Proveedor_BC', 'Fecha_Posteo_BC', 'Error_Posteo_BC']);
        });
    }
};
