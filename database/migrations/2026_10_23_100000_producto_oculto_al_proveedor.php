<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El personal de "Productos por proveedor" puede OCULTARLE un producto al
 * proveedor (pedido del usuario, 23-oct-2026): el proveedor deja de verlo
 * en su lista, sus conteos, al registrar y en Hana; los internos lo siguen
 * viendo, en gris. Ver Producto::scopeParaQuienMira.
 *
 * Por defecto 0: nada cambia para los productos que ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->boolean('Oculto_Proveedor')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            // SQL Server no deja borrar una columna con DEFAULT sin borrar
            // antes su restricción: dropColumn de Laravel se encarga.
            $table->dropColumn('Oculto_Proveedor');
        });
    }
};
