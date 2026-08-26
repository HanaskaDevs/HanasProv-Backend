<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de que ya se escaló "este proveedor entregó y nadie calificó la
 * recepción".
 *
 * El comando que manda esa alerta corre CADA HORA (tiene que hacerlo: la
 * condición se cumple una hora después de que se marcó la entrega, y eso
 * pasa a cualquier hora del día). Sin esta columna, el mismo caso se
 * reportaría en cada corrida y los destinatarios recibirían el mismo correo
 * una vez por hora hasta el fin del día.
 *
 * Es un datetime y no un bit a propósito: además de "ya se avisó" queda
 * CUÁNDO se avisó, que es lo que permite reconstruir después por qué una
 * recepción se pasó sin calificar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Horario_Entrega_Estado_Diario', function (Blueprint $table) {
            $table->dateTime('Alerta_Sin_Calificacion_Enviada')
                ->nullable()
                ->after('Marcado_Entregado_Por');
        });
    }

    public function down(): void
    {
        Schema::table('Horario_Entrega_Estado_Diario', function (Blueprint $table) {
            $table->dropColumn('Alerta_Sin_Calificacion_Enviada');
        });
    }
};
