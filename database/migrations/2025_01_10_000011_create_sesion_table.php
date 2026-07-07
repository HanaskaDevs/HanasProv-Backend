<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Sesion', function (Blueprint $table) {
            $table->id('Id_Sesion');
            $table->unsignedBigInteger('Id_Usuario');
            $table->string('Token', 255);
            $table->string('Ip_Origen', 45)->nullable();
            $table->string('Dispositivo', 200)->nullable();
            $table->dateTime('Fecha_Inicio')->useCurrent();
            $table->dateTime('Fecha_Expiracion');
            $table->boolean('Activa')->default(true);
            $table->unsignedBigInteger('Id_Empresa_Activa')->nullable();

            $table->foreign('Id_Usuario', 'FK_Sesion_Usuario')
                ->references('Id_Usuario')->on('Usuario');
            $table->foreign('Id_Empresa_Activa', 'FK_Sesion_EmpresaActiva')
                ->references('Id_Empresa')->on('Empresa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Sesion');
    }
};
