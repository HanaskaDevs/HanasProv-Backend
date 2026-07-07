<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Empresa', function (Blueprint $table) {
            $table->id('Id_Empresa');
            $table->string('Razon_Social', 200);
            $table->string('Ruc', 13)->unique();
            $table->string('Nombre_Comercial', 200)->nullable();
            $table->string('Logo_Url', 500)->nullable();
            $table->boolean('Activo')->default(true);
            $table->unsignedBigInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->useCurrent();
            $table->unsignedBigInteger('Modificado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Empresa');
    }
};
