<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rol "Bodega" (pedido del usuario, 25-nov-2026): el personal de bodega.
 * Entra SOLO a cuatro pantallas, todas de consulta:
 *   - Pedidos (pedidos por bodega, de TODAS las bodegas, como Admin y
 *     Sistemas: decisión del usuario, sin asignarle bodegas),
 *   - Historial de proveedores,
 *   - Calendario de horarios (con el Modo TV),
 *   - Pedidos fuera de planificación.
 * No marca arribos, no resuelve aprobaciones, no ve reclamos ni nada más.
 *
 * Va por migración (como Catálogo y Modo TV) porque la tabla Rol de
 * producción ya tiene sus filas y el seeder no se vuelve a correr. Correrla
 * dos veces no duplica nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('Rol')->where('Nombre_Rol', 'Bodega')->exists()) {
            return;
        }

        DB::table('Rol')->insert([
            'Nombre_Rol' => 'Bodega',
            'Descripcion' => 'Personal de bodega: consulta pedidos, historial de proveedores, calendario y pedidos fuera de planificación',
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function down(): void
    {
        // Solo si nadie lo tiene asignado: con usuarios vinculados el DELETE
        // fallaría por la FK de Usuario_Empresa, y es mejor que falle acá
        // diciendo por qué.
        $idRol = DB::table('Rol')->where('Nombre_Rol', 'Bodega')->value('Id_Rol');

        if ($idRol === null) {
            return;
        }

        if (DB::table('Usuario_Empresa')->where('Id_Rol', $idRol)->exists()) {
            throw new RuntimeException('Hay usuarios con el rol Bodega: reasignarles otro rol antes de revertir.');
        }

        DB::table('Rol')->where('Id_Rol', $idRol)->delete();
    }
};
