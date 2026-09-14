<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Volumen del masterpack (pedido del usuario, 12-sep-2026).
 *
 * La columna Volumen que ya existía es la de UNA unidad suelta. Esta es la
 * de la caja completa, y no es lo mismo ni se deduce una de la otra: entre
 * las unidades hay separadores, relleno y el cartón de la propia caja, así
 * que el volumen del masterpack NO es el de la unidad por la cantidad que
 * trae. Son dos medidas distintas y las dos hacen falta -la de la unidad
 * para el espacio en góndola, la de la caja para cuántas entran en un
 * pallet o en un camión-.
 *
 * Se GUARDA en vez de calcularse al vuelo en cada pantalla por la misma
 * razón que Volumen: es un dato que van a querer exportar y cruzar con
 * otros sistemas, y recalcularlo en cada lugar que lo muestre es la forma
 * de que dos pantallas terminen diciendo cosas distintas.
 *
 * decimal(12,6): mismo motivo que en la migración 2026_09_12_090000 -el
 * volumen va en m³ y sale de medidas en cm, así que hacen falta los
 * decimales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->decimal('Volumen_Masterpack', 12, 6)->nullable()->after('Volumen');
        });
    }

    public function down(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->dropColumn('Volumen_Masterpack');
        });
    }
};
