<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Proveedor_Categoria_Producto', function (Blueprint $table) {
            $table->id('Id_Proveedor_Categoria');
            $table->unsignedBigInteger('Id_Proveedor');
            $table->unsignedBigInteger('Id_Categoria_Producto');
            $table->boolean('Activo')->default(true);

            $table->unique(['Id_Proveedor', 'Id_Categoria_Producto'], 'UQ_ProveedorCategoria');

            $table->foreign('Id_Proveedor', 'FK_ProvCategoria_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
            $table->foreign('Id_Categoria_Producto', 'FK_ProvCategoria_Categoria')
                ->references('Id_Categoria_Producto')->on('Categoria_Producto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Proveedor_Categoria_Producto');
    }
};
