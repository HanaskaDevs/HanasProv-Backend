<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rol "Modo TV" (pedido del usuario, 11-nov-2026): la cuenta que se deja
 * abierta en las TVs de recepción. Solo ve el calendario en Modo TV y su
 * sesión no vence (ver AuthService::login y Usuario::esPantallaTv).
 *
 * Para que un token pueda no vencer, el vencimiento pasó de ser global
 * (sanctum.expiration = 24 h, contadas desde que se creó cada token) a ir en
 * cada token (expires_at). Los tokens que ya existían no tienen expires_at:
 * acá se les pone el mismo vencimiento que tenían, creación + 24 h, para que
 * no queden válidos para siempre.
 *
 * Va por migración (como el rol Catálogo) porque la tabla Rol de producción
 * ya tiene sus filas y el seeder no se vuelve a correr. Correrla dos veces no
 * duplica nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('Rol')->where('Nombre_Rol', 'Modo TV')->exists()) {
            DB::table('Rol')->insert([
                'Nombre_Rol' => 'Modo TV',
                'Descripcion' => 'Cuenta de las pantallas de TV: solo ve el calendario en Modo TV y su sesión no vence',
                'Activo' => true,
                'Fecha_Creacion' => now(),
            ]);
        }

        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->update(['expires_at' => DB::raw('DATEADD(hour, 24, created_at)')]);
    }

    public function down(): void
    {
        $idRol = DB::table('Rol')->where('Nombre_Rol', 'Modo TV')->value('Id_Rol');

        if ($idRol === null) {
            return;
        }

        if (DB::table('Usuario_Empresa')->where('Id_Rol', $idRol)->exists()) {
            throw new RuntimeException('Hay usuarios con el rol Modo TV: reasignarles otro rol antes de revertir.');
        }

        DB::table('Rol')->where('Id_Rol', $idRol)->delete();
    }
};
