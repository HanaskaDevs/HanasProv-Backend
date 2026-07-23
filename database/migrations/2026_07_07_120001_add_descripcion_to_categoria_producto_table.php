<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Categoria_Producto', function (Blueprint $table) {
            $table->string('Descripcion', 100)->nullable()->after('Nombre_Categoria');
        });
    }

    public function down(): void
    {
        Schema::table('Categoria_Producto', function (Blueprint $table) {
            $table->dropColumn('Descripcion');
        });
    }
};
