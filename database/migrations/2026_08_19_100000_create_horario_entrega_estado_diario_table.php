<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento EN VIVO de cada entrega, día por día (pedido del usuario:
 * el Guardia marca "arribó" y a los 5 minutos pasa solo a "En recepción";
 * Compras marca "entregado"; "atrasado" sale solo cuando ya pasó la hora
 * de llegada programada). Horario_Entrega_Proveedor es la plantilla
 * SEMANAL (se repite cada lunes, cada martes...), esta tabla es la
 * ocurrencia real de UN día concreto -> por eso va aparte y no como
 * columnas en la plantilla: si viviera ahí, "marcar arribó" de esta
 * semana se vería reflejado (o pisado) la semana siguiente.
 *
 * Guarda solo los 2 eventos que se marcan A MANO (arribo/entregado, con
 * fecha y hora REAL, no la programada). "Atrasado" y "En recepción" NO
 * se guardan -> se calculan al vuelo comparando la hora actual contra
 * Hora_Llegada de la plantilla y contra Hora_Arribo_Real + 5 minutos
 * (ver HorarioEntregaService::calcularEstado). Así no se necesita un
 * cron corriendo cada minuto para "pasar" los estados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Horario_Entrega_Estado_Diario', function (Blueprint $table) {
            $table->id('Id_Horario_Entrega_Estado_Diario');
            $table->unsignedBigInteger('Id_Horario_Entrega_Proveedor');
            $table->date('Fecha');

            $table->dateTime('Hora_Arribo_Real')->nullable();
            $table->unsignedInteger('Marcado_Arribo_Por')->nullable();

            $table->dateTime('Hora_Entregado_Real')->nullable();
            $table->unsignedInteger('Marcado_Entregado_Por')->nullable();

            $table->dateTime('Fecha_Creacion')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->foreign('Id_Horario_Entrega_Proveedor', 'FK_HorarioEstadoDiario_Horario')
                ->references('Id_Horario_Entrega_Proveedor')->on('Horario_Entrega_Proveedor');
            $table->foreign('Marcado_Arribo_Por', 'FK_HorarioEstadoDiario_UsuarioArribo')
                ->references('Id_Usuario')->on('Usuario');
            $table->foreign('Marcado_Entregado_Por', 'FK_HorarioEstadoDiario_UsuarioEntregado')
                ->references('Id_Usuario')->on('Usuario');

            // Una sola fila por horario y por día -> evita marcar "arribó"
            // dos veces el mismo día para el mismo proveedor.
            $table->unique(['Id_Horario_Entrega_Proveedor', 'Fecha'], 'UX_HorarioEstadoDiario_Horario_Fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Horario_Entrega_Estado_Diario');
    }
};
