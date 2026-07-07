<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Rol', function (Blueprint $table) {
            $table->id('Id_Rol');
            $table->string('Nombre_Rol', 50)->unique();
            $table->string('Descripcion', 200)->nullable();
            $table->boolean('Activo')->default(true);
            $table->unsignedBigInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Rol');
    }
};
