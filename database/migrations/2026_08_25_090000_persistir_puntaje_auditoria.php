<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda el puntaje de la auditoría en la propia fila de Auditoria, igual
 * que Calificacion_Recepcion ya hace con Puntaje_Obtenido /
 * Porcentaje_Obtenido (misma precisión, mismos nombres donde aplica).
 *
 * Hasta ahora el porcentaje se recalculaba SIEMPRE al vuelo desde
 * Auditoria_Respuesta + Auditoria_Pregunta (ver
 * AuditoriaService::calcularResumen). Eso trae dos problemas:
 *
 *  1. CORRECTITUD. El cálculo lee Puntaje_Max del catálogo de preguntas
 *     VIGENTE, no del que existía cuando se auditó -> si alguien edita el
 *     puntaje de una pregunta, o desactiva una, el porcentaje de una
 *     auditoría YA FINALIZADA cambia retroactivamente. Una auditoría
 *     cerrada es un hecho histórico y no debería moverse.
 *
 *  2. COSTO. La calificación global del proveedor necesita este porcentaje
 *     (vale el 15% de la nota). Sin persistirlo, listar N proveedores con
 *     su nota obliga a reconstruir cada auditoría pregunta por pregunta.
 *
 * Se agrega también Puntaje_No_Aplica porque en auditorías (a diferencia
 * de recepciones) el auditor puede marcar preguntas como "No aplica", y
 * ese puntaje se descuenta del total posible antes de sacar el
 * porcentaje: sin guardarlo no se puede explicar de dónde salió la nota.
 *
 * Las columnas son nullable y solo se llenan al finalizar. Un Borrador las
 * tiene en NULL, que es justamente cómo se distingue "sin nota todavía".
 * No hace falta rellenar nada retroactivo: no hay ninguna auditoría en
 * estado Finalizada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Auditoria', function (Blueprint $table) {
            $table->decimal('Puntaje_Total_Posible', 8, 2)->nullable()->after('Estado');
            $table->decimal('Puntaje_No_Aplica', 8, 2)->nullable()->after('Puntaje_Total_Posible');
            $table->decimal('Puntaje_Obtenido', 8, 2)->nullable()->after('Puntaje_No_Aplica');
            $table->decimal('Porcentaje_Cumplimiento', 5, 2)->nullable()->after('Puntaje_Obtenido');
        });
    }

    public function down(): void
    {
        Schema::table('Auditoria', function (Blueprint $table) {
            $table->dropColumn([
                'Puntaje_Total_Posible',
                'Puntaje_No_Aplica',
                'Puntaje_Obtenido',
                'Porcentaje_Cumplimiento',
            ]);
        });
    }
};
