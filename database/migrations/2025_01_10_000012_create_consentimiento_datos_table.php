<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Consentimiento_Datos', function (Blueprint $table) {
            $table->id('Id_Consentimiento');
            $table->unsignedBigInteger('Id_Usuario');
            $table->string('Version_Politica', 20);
            $table->string('Ip_Origen', 45);
            $table->dateTime('Fecha_Aceptacion')->useCurrent();

            $table->foreign('Id_Usuario', 'FK_Consentimiento_Usuario')
                ->references('Id_Usuario')->on('Usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Consentimiento_Datos');
    }
};
