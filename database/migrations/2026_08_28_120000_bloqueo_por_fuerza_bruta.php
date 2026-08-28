<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Defensa contra fuerza bruta en el login, en sus dos formas:
 *
 * 1. CONTRA UNA CUENTA CONCRETA (alguien que sabe el correo y prueba
 *    contraseñas). A los 3 fallos seguidos el usuario queda bloqueado y
 *    solo Sistemas lo reactiva.
 *
 *    Se marca con una columna PROPIA y no reusando Activo=0 a propósito:
 *    "lo inactivó un administrador" y "se bloqueó solo por intentos
 *    fallidos" son dos cosas distintas, se resuelven distinto y la
 *    pantalla de usuarios tiene que poder mostrar cuál es cuál. Con una
 *    sola bandera para las dos, Sistemas no sabría si al reactivar está
 *    deshaciendo una decisión de alguien o destrabando un ataque.
 *
 * 2. CONTRA EL PORTAL EN GENERAL (alguien que prueba correos al azar a
 *    ver cuál existe). A los 5 intentos seguidos con usuarios que NO
 *    existen, se bloquea la IP.
 *
 *    El bloqueo de IP es PERMANENTE hasta que Sistemas lo levante
 *    (decisión del negocio, 28-ago-2026): no se autolevanta con el
 *    tiempo. Por eso es una tabla y no una entrada de caché -> tiene que
 *    sobrevivir a un reinicio, quedar auditada (quién la bloqueó, quién
 *    la liberó) y poder listarse en una pantalla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Usuario', function (Blueprint $table) {
            $table->boolean('Bloqueado_Por_Intentos')->default(false)->after('Activo');
            $table->dateTime('Fecha_Bloqueo')->nullable()->after('Bloqueado_Por_Intentos');
        });

        Schema::create('Ip_Bloqueada', function (Blueprint $table) {
            $table->id('Id_Ip_Bloqueada');
            // 45 caracteres = largo máximo de una IPv6 en texto.
            $table->string('Ip', 45);
            $table->string('Motivo', 200)->nullable();
            $table->integer('Intentos')->default(0);
            $table->dateTime('Fecha_Bloqueo');
            // Id_Usuario es INT en esta base (no BIGINT): la FK tiene que
            // declararse unsignedInteger o SQL Server la rechaza.
            $table->unsignedInteger('Desbloqueada_Por')->nullable();
            $table->dateTime('Fecha_Desbloqueo')->nullable();
            // Activa = el bloqueo sigue vigente. Al liberar una IP NO se
            // borra la fila: queda el histórico de que esa IP atacó.
            $table->boolean('Activa')->default(true);

            // Se consulta en CADA login ("¿esta IP está bloqueada?"), así
            // que sin este índice el login pagaría un scan completo.
            $table->index(['Ip', 'Activa'], 'IX_Ip_Bloqueada_Ip_Activa');
        });

        // Bitacora_Acceso se consulta en cada login para contar los fallos
        // seguidos, tanto por usuario como por IP. Sin índices, ese conteo
        // crece con el tamaño de la tabla y el login se vuelve más lento
        // cuanto más se usa el portal.
        Schema::table('Bitacora_Acceso', function (Blueprint $table) {
            $table->index(['Id_Usuario', 'Fecha_Evento'], 'IX_Bitacora_Usuario_Fecha');
            $table->index(['Ip_Origen', 'Fecha_Evento'], 'IX_Bitacora_Ip_Fecha');
        });
    }

    public function down(): void
    {
        Schema::table('Bitacora_Acceso', function (Blueprint $table) {
            $table->dropIndex('IX_Bitacora_Usuario_Fecha');
            $table->dropIndex('IX_Bitacora_Ip_Fecha');
        });

        Schema::dropIfExists('Ip_Bloqueada');

        Schema::table('Usuario', function (Blueprint $table) {
            $table->dropColumn(['Bloqueado_Por_Intentos', 'Fecha_Bloqueo']);
        });
    }
};
