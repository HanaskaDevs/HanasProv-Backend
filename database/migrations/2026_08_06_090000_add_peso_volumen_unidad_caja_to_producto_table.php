<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos nuevos de la ficha de producto pedidos por el negocio: Peso
 * (kg), Volumen (m3) y Unidad_Por_Caja (cuántas unidades trae cada
 * caja/empaque). Los 3 son opcionales (nullable) porque los productos
 * que ya existen -tanto los cargados a mano como los sincronizados
 * desde BC- no tienen este dato y no se les puede exigir retroactivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->decimal('Peso', 10, 3)->nullable()->after('Precio');
            $table->decimal('Volumen', 10, 3)->nullable()->after('Peso');
            $table->unsignedInteger('Unidad_Por_Caja')->nullable()->after('Volumen');
        });
    }

    public function down(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->dropColumn(['Peso', 'Volumen', 'Unidad_Por_Caja']);
        });
    }
};
