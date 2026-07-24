<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Auditoria_Respuesta', function (Blueprint $table) {
            $table->id('Id_Auditoria_Respuesta');
            $table->unsignedBigInteger('Id_Auditoria');
            $table->unsignedBigInteger('Id_Auditoria_Pregunta');
            $table->decimal('Puntaje_Obtenido', 5, 2)->nullable();
            $table->boolean('No_Aplica')->default(false);
            $table->string('Observacion', 500)->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->foreign('Id_Auditoria', 'FK_AuditoriaRespuesta_Auditoria')
                ->references('Id_Auditoria')->on('Auditoria');
            $table->foreign('Id_Auditoria_Pregunta', 'FK_AuditoriaRespuesta_Pregunta')
                ->references('Id_Auditoria_Pregunta')->on('Auditoria_Pregunta');
            $table->unique(['Id_Auditoria', 'Id_Auditoria_Pregunta'], 'UQ_AuditoriaRespuesta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Auditoria_Respuesta');
    }
};