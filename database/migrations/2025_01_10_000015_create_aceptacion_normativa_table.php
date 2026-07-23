<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Aceptacion_Normativa', function (Blueprint $table) {
            $table->id('Id_Aceptacion_Normativa');
            $table->unsignedBigInteger('Id_Proveedor');
            $table->unsignedBigInteger('Id_Usuario');
            $table->string('Cargo_Firmante', 100);
            $table->string('Codigo_Documento', 30)->default('FGH04.15.02');
            $table->string('Version_Documento', 20);
            $table->string('Ip_Origen', 45);
            $table->dateTime('Fecha_Aceptacion')->useCurrent();

            $table->foreign('Id_Proveedor', 'FK_Aceptacion_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
            $table->foreign('Id_Usuario', 'FK_Aceptacion_Usuario')
                ->references('Id_Usuario')->on('Usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Aceptacion_Normativa');
    }
};
