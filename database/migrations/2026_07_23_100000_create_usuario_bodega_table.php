<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Usuario_Bodega', function (Blueprint $table) {
            $table->id('Id_Usuario_Bodega');
            $table->unsignedInteger('Id_Usuario');
            $table->unsignedInteger('Id_Empresa');
            $table->string('Cod_Almacen', 20);
            $table->boolean('Activo')->default(true);
            $table->unsignedInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion');

            $table->foreign('Id_Usuario', 'FK_UsuarioBodega_Usuario')
                ->references('Id_Usuario')->on('Usuario');
            $table->foreign('Id_Empresa', 'FK_UsuarioBodega_Empresa')
                ->references('Id_Empresa')->on('Empresa');
            $table->unique(['Id_Usuario', 'Id_Empresa', 'Cod_Almacen'], 'UQ_UsuarioBodega');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Usuario_Bodega');
    }
};