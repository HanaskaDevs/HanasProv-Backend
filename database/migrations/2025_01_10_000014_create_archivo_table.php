<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Archivo', function (Blueprint $table) {
            $table->id('Id_Archivo');
            $table->unsignedBigInteger('Id_Proveedor')->nullable();
            $table->string('Nombre_Original', 255);
            $table->string('Ruta_Almacenamiento', 500);
            $table->string('Hash_Archivo', 128);
            $table->string('Tipo_Mime', 100);
            $table->bigInteger('Tamano_Bytes');
            $table->string('Categoria_Archivo', 50);
            $table->unsignedBigInteger('Id_Usuario_Carga');
            $table->dateTime('Fecha_Carga')->useCurrent();
            $table->boolean('Activo')->default(true);

            $table->foreign('Id_Proveedor', 'FK_Archivo_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
            $table->foreign('Id_Usuario_Carga', 'FK_Archivo_Usuario')
                ->references('Id_Usuario')->on('Usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Archivo');
    }
};
