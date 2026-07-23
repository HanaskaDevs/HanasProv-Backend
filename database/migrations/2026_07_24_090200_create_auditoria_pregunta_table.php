<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Auditoria_Pregunta', function (Blueprint $table) {
            $table->id('Id_Auditoria_Pregunta');
            $table->unsignedBigInteger('Id_Auditoria_Seccion');
            // Número de fila real de la hoja original (1, 2, 3... 27, 28...)
            // -> se conserva aunque falten filas para no tener que
            // renumerar nada cuando se agreguen después.
            $table->unsignedInteger('Numero');
            $table->string('Descripcion', 500);
            $table->decimal('Puntaje_Max', 5, 2);
            $table->unsignedInteger('Orden')->default(0);
            $table->boolean('Activo')->default(true);

            $table->foreign('Id_Auditoria_Seccion', 'FK_AuditoriaPregunta_Seccion')
                ->references('Id_Auditoria_Seccion')->on('Auditoria_Seccion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Auditoria_Pregunta');
    }
};