<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Codigo_Activacion', function (Blueprint $table) {
            $table->id('Id_Codigo_Activacion');
            $table->string('Email', 150);
            $table->unsignedBigInteger('Id_Proveedor')->nullable();
            $table->string('Tipo', 20);
            $table->string('Codigo', 10);
            $table->dateTime('Fecha_Expiracion');
            $table->boolean('Usado')->default(false);
            $table->dateTime('Fecha_Uso')->nullable();
            $table->unsignedBigInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->useCurrent();

            $table->foreign('Id_Proveedor', 'FK_CodigoActivacion_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Codigo_Activacion');
    }
};
