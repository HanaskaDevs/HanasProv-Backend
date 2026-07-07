<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Categoria_Producto', function (Blueprint $table) {
            $table->id('Id_Categoria_Producto');
            $table->string('Nombre_Categoria', 150);
            $table->unsignedBigInteger('Id_Categoria_Padre')->nullable();
            $table->boolean('Activo')->default(true);

            $table->foreign('Id_Categoria_Padre', 'FK_Categoria_Padre')
                ->references('Id_Categoria_Producto')->on('Categoria_Producto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Categoria_Producto');
    }
};
