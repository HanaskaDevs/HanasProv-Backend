<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Politica', function (Blueprint $table) {
            $table->id('Id_Politica');
            $table->unsignedInteger('Orden');
            $table->string('Titulo', 200);
            $table->text('Descripcion');
            $table->boolean('Activo')->default(true);
            $table->unsignedInteger('Modificado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->foreign('Modificado_Por', 'FK_Politica_Usuario')
                ->references('Id_Usuario')->on('Usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Politica');
    }
};