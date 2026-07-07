<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Historial_Estado_Proveedor', function (Blueprint $table) {
            $table->id('Id_Historial');
            $table->unsignedBigInteger('Id_Proveedor');
            $table->unsignedBigInteger('Id_Estado_Anterior')->nullable();
            $table->unsignedBigInteger('Id_Estado_Nuevo');
            $table->string('Motivo', 300)->nullable();
            $table->unsignedBigInteger('Id_Usuario')->nullable();
            $table->dateTime('Fecha_Cambio')->useCurrent();

            $table->foreign('Id_Proveedor', 'FK_Historial_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
            $table->foreign('Id_Estado_Anterior', 'FK_Historial_EstadoAnterior')
                ->references('Id_Estado_Proveedor')->on('Estado_Proveedor');
            $table->foreign('Id_Estado_Nuevo', 'FK_Historial_EstadoNuevo')
                ->references('Id_Estado_Proveedor')->on('Estado_Proveedor');
            $table->foreign('Id_Usuario', 'FK_Historial_Usuario')
                ->references('Id_Usuario')->on('Usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Historial_Estado_Proveedor');
    }
};
