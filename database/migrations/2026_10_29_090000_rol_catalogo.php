<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rol "Catálogo" (pedido del usuario, 29-oct-2026): mantiene los datos de los
 * productos. Entra a Catálogo de productos (carga los códigos de BC),
 * Productos por proveedor y Productos por revisar, y edita los DATOS de
 * cualquier producto, esté o no aprobado, sin cambiarle el estado. No carga
 * ni borra documentos, no crea ni elimina productos y no aprueba ni rechaza.
 *
 * Va por migración (y no solo en RolEstadoProveedorSeeder) porque la tabla
 * Rol de producción ya tiene sus filas y el seeder no se vuelve a correr. Se
 * inserta solo si no existe, así que correrla dos veces no duplica nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('Rol')->where('Nombre_Rol', 'Catálogo')->exists()) {
            return;
        }

        DB::table('Rol')->insert([
            'Nombre_Rol' => 'Catálogo',
            'Descripcion' => 'Mantiene los datos y los códigos BC de los productos',
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function down(): void
    {
        // Solo si nadie lo tiene asignado: con usuarios vinculados el DELETE
        // fallaría por la FK de Usuario_Empresa, y es mejor que falle acá
        // diciendo por qué.
        $idRol = DB::table('Rol')->where('Nombre_Rol', 'Catálogo')->value('Id_Rol');

        if ($idRol === null) {
            return;
        }

        if (DB::table('Usuario_Empresa')->where('Id_Rol', $idRol)->exists()) {
            throw new RuntimeException('Hay usuarios con el rol Catálogo: reasignarles otro rol antes de revertir.');
        }

        DB::table('Rol')->where('Id_Rol', $idRol)->delete();
    }
};
