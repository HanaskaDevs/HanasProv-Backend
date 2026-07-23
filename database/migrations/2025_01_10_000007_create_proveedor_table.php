<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Proveedor', function (Blueprint $table) {
            $table->id('Id_Proveedor');
            $table->unsignedBigInteger('Id_Empresa');
            $table->unsignedBigInteger('Id_Estado_Proveedor');
            $table->string('Ruc', 13);
            $table->string('Razon_Social', 200);
            $table->string('Nombre_Comercial', 200)->nullable();
            $table->string('Email', 150);
            $table->string('Telefono', 20)->nullable();
            $table->string('Direccion', 300)->nullable();
            $table->decimal('Latitud', 10, 7)->nullable();
            $table->decimal('Longitud', 10, 7)->nullable();
            $table->tinyInteger('Seccion_Actual')->default(1);
            $table->tinyInteger('Porcentaje_Completado_Ficha')->default(0);
            $table->dateTime('Fecha_Postulacion')->useCurrent();
            $table->dateTime('Fecha_Aprobacion')->nullable();
            $table->boolean('Activo')->default(true);
            $table->unsignedBigInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->useCurrent();
            $table->unsignedBigInteger('Modificado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->unique(['Id_Empresa', 'Ruc'], 'UQ_Proveedor_Empresa_Ruc');

            $table->foreign('Id_Empresa', 'FK_Proveedor_Empresa')
                ->references('Id_Empresa')->on('Empresa');
            $table->foreign('Id_Estado_Proveedor', 'FK_Proveedor_Estado')
                ->references('Id_Estado_Proveedor')->on('Estado_Proveedor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Proveedor');
    }
};
