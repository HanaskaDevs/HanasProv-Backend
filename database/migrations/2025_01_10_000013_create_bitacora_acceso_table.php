<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Bitacora_Acceso', function (Blueprint $table) {
            $table->id('Id_Bitacora');
            $table->unsignedBigInteger('Id_Usuario')->nullable();
            $table->string('Email_Intento', 150)->nullable();
            $table->string('Tipo_Evento', 30);
            $table->string('Ip_Origen', 45)->nullable();
            $table->string('User_Agent', 300)->nullable();
            $table->dateTime('Fecha_Evento')->useCurrent();

            $table->foreign('Id_Usuario', 'FK_Bitacora_Usuario')
                ->references('Id_Usuario')->on('Usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Bitacora_Acceso');
    }
};
