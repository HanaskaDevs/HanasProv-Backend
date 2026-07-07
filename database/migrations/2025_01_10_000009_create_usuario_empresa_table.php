<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Usuario_Empresa', function (Blueprint $table) {
            $table->id('Id_Usuario_Empresa');
            $table->unsignedBigInteger('Id_Usuario');
            $table->unsignedBigInteger('Id_Empresa');
            $table->unsignedBigInteger('Id_Rol');
            $table->boolean('Activo')->default(true);
            $table->unsignedBigInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->useCurrent();
            $table->unsignedBigInteger('Modificado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->unique(['Id_Usuario', 'Id_Empresa'], 'UQ_UsuarioEmpresa');

            $table->foreign('Id_Usuario', 'FK_UsuarioEmpresa_Usuario')
                ->references('Id_Usuario')->on('Usuario');
            $table->foreign('Id_Empresa', 'FK_UsuarioEmpresa_Empresa')
                ->references('Id_Empresa')->on('Empresa');
            $table->foreign('Id_Rol', 'FK_UsuarioEmpresa_Rol')
                ->references('Id_Rol')->on('Rol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Usuario_Empresa');
    }
};
