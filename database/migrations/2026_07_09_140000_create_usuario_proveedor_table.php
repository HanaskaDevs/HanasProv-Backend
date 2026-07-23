<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documenta la tabla puente Usuario_Proveedor (reemplaza a la antigua
 * columna Usuario.Id_Proveedor), permitiendo que varios usuarios se
 * vinculen al mismo Proveedor. Ya fue aplicada manualmente en SQL Server
 * -> esta migration queda como historial, se marca como ya ejecutada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Usuario_Proveedor', function (Blueprint $table) {
            $table->id('Id_Usuario_Proveedor');
            $table->unsignedBigInteger('Id_Usuario');
            $table->unsignedBigInteger('Id_Proveedor');
            $table->boolean('Activo')->default(true);
            $table->unsignedBigInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->useCurrent();

            $table->unique(['Id_Usuario', 'Id_Proveedor'], 'UQ_UsuarioProveedor');

            $table->foreign('Id_Usuario', 'FK_UsuarioProveedor_Usuario')
                ->references('Id_Usuario')->on('Usuario');
            $table->foreign('Id_Proveedor', 'FK_UsuarioProveedor_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Usuario_Proveedor');
    }
};
