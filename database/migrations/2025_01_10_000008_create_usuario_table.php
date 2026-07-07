<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Usuario', function (Blueprint $table) {
            $table->id('Id_Usuario');
            $table->unsignedBigInteger('Id_Proveedor')->nullable();
            $table->string('Email', 150)->unique();
            $table->string('Password_Hash', 255);
            $table->string('Nombre_Completo', 200);
            $table->string('Cargo', 100)->nullable();
            $table->string('Telefono', 20)->nullable();
            $table->boolean('Requiere_Cambio_Password')->default(false);
            $table->dateTime('Ultimo_Acceso')->nullable();
            $table->boolean('Activo')->default(true);
            $table->unsignedBigInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->useCurrent();
            $table->unsignedBigInteger('Modificado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();
            $table->string('Tipo_Usuario', 20)->default('Interno');

            $table->foreign('Id_Proveedor', 'FK_Usuario_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Usuario');
    }
};
