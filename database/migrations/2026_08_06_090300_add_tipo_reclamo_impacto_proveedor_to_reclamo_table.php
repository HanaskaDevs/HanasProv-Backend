<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos clasificaciones nuevas que se piden al crear un reclamo:
 * - Tipo_Reclamo: 'Calidad' | 'Salubridad' | 'Inocuidad'
 * - Impacto_Proveedor: 'Alto' | 'Medio' | 'Bajo'
 * Nullable porque los reclamos ya existentes no tienen este dato
 * (no se les puede exigir retroactivo), y el valor permitido se
 * valida en la capa de aplicación (CrearReclamoRequest), igual que
 * ya se hace con 'Estado' en esta misma tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Reclamo', function (Blueprint $table) {
            $table->string('Tipo_Reclamo', 20)->nullable()->after('Asunto');
            $table->string('Impacto_Proveedor', 10)->nullable()->after('Tipo_Reclamo');
        });
    }

    public function down(): void
    {
        Schema::table('Reclamo', function (Blueprint $table) {
            $table->dropColumn(['Tipo_Reclamo', 'Impacto_Proveedor']);
        });
    }
};
