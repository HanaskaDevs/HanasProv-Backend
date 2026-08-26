<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumo del asistente (Hana) contra la API de Claude, una fila por
 * llamada.
 *
 * Existe para poder CORTAR el gasto, no solo para reportarlo: antes de
 * cada llamada se suman los costos de la semana y del mes y, si se pasó
 * del tope, no se llama a la API (ver AsistentePresupuestoService). Sin
 * esta tabla el único freno sería el saldo de la cuenta de Anthropic, que
 * se entera cuando ya se gastó.
 *
 * Se guardan los tokens además del costo a propósito: el precio por
 * millón de tokens puede cambiar, y con los tokens crudos se puede
 * recalcular el histórico. Si solo guardáramos el costo, un cambio de
 * tarifa dejaría los números viejos sin forma de auditarlos.
 *
 * Costo_Usd es decimal(10,6): una consulta típica de Hana cuesta
 * fracciones de centavo (del orden de 0.0005 USD), así que con 2 decimales
 * TODAS las filas quedarían en 0.00 y la suma nunca alcanzaría el tope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Asistente_Consumo', function (Blueprint $table) {
            $table->bigIncrements('Id_Asistente_Consumo');

            $table->unsignedInteger('Id_Usuario')->nullable();
            $table->unsignedInteger('Id_Empresa')->nullable();

            $table->string('Modelo', 60);
            $table->unsignedInteger('Tokens_Entrada')->default(0);
            $table->unsignedInteger('Tokens_Salida')->default(0);
            $table->unsignedInteger('Tokens_Cache_Escritura')->default(0);
            $table->unsignedInteger('Tokens_Cache_Lectura')->default(0);
            $table->decimal('Costo_Usd', 10, 6)->default(0);

            // 'Mensaje' | 'Bienvenida' -> para saber qué parte del gasto
            // viene de conversación real y qué parte del saludo proactivo.
            $table->string('Origen', 20)->default('Mensaje');

            $table->dateTime('Fecha_Creacion');

            // La consulta caliente es "cuánto se gastó desde tal fecha",
            // que corre ANTES de cada llamada al asistente. Sin este
            // índice sería un scan completo de la tabla en cada mensaje,
            // y esta tabla solo crece.
            $table->index('Fecha_Creacion', 'IX_Asistente_Consumo_Fecha');
            $table->index('Id_Usuario', 'IX_Asistente_Consumo_Usuario');

            $table->foreign('Id_Usuario')->references('Id_Usuario')->on('Usuario');
            $table->foreign('Id_Empresa')->references('Id_Empresa')->on('Empresa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Asistente_Consumo');
    }
};
