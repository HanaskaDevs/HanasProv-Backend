<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué pasó con el correo de cada código de activación (12-oct-2026).
 *
 * POR QUÉ: desbloquear una cuenta, reenviar la activación o crear un
 * usuario esperaban, dentro de la misma petición, a que el servidor de
 * correo aceptara el mensaje. Eso es lo que permitía decir "se envió" o
 * "no se envió, por esto" con certeza, pero también lo que hacía esperar
 * varios segundos a quien apretó el botón.
 *
 * Ahora la petición responde enseguida y el correo sale JUSTO DESPUÉS, en
 * el mismo proceso. Estas columnas son donde ese envío deja su resultado,
 * para que la pantalla lo vaya mostrando en vivo sin perder la certeza:
 *
 *   Estado_Envio = 'Enviando' -> todavía no hay respuesta del servidor
 *                  'Enviado'  -> el servidor de correo lo aceptó
 *                  'Fallido'  -> lo rechazó; el motivo en Detalle_Envio
 *                  NULL       -> códigos viejos, o envíos por la cola
 *                                (carga masiva), que no se siguen así
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Codigo_Activacion', function (Blueprint $table) {
            $table->string('Estado_Envio', 10)->nullable();
            $table->string('Detalle_Envio', 500)->nullable();
            $table->dateTime('Fecha_Envio')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Codigo_Activacion', function (Blueprint $table) {
            $table->dropColumn(['Estado_Envio', 'Detalle_Envio', 'Fecha_Envio']);
        });
    }
};
