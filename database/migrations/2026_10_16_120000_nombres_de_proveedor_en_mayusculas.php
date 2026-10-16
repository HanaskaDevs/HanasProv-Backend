<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Razón social y nombre comercial de los proveedores QUE YA EXISTEN, en
 * mayúsculas, y sin nombre comercial vacío ni "N/A": se completa con la
 * razón social (pedido explícito del usuario, 16-oct-2026). Los que se
 * guarden de acá en adelante ya entran así (ver Proveedor::booted).
 *
 * Al escribirla (16-oct-2026) eran 147 proveedores activos: 44 con la razón
 * social en minúsculas, 70 sin nombre comercial, 1 con "N/A" y 23 con el
 * nombre comercial en minúsculas.
 *
 * OJO: desarrollo y producción comparten la base Portal_Proveedores, así
 * que correr esta migración en cualquiera de los dos cambia los datos de
 * ambos.
 *
 * Solo toca la tabla Proveedor del portal. Lo que ya se posteó a Business
 * Central queda como estaba allá.
 */
return new class extends Migration
{
    public function up(): void
    {
        // UPPER de SQL Server respeta la intercalación de la columna: la ñ y
        // las vocales con tilde también pasan a mayúscula.
        DB::table('Proveedor')->update([
            'Razon_Social' => DB::raw('UPPER(LTRIM(RTRIM(Razon_Social)))'),
            'Nombre_Comercial' => DB::raw("
                CASE
                    WHEN Nombre_Comercial IS NULL
                      OR LTRIM(RTRIM(Nombre_Comercial)) = ''
                      OR UPPER(LTRIM(RTRIM(Nombre_Comercial))) = 'N/A'
                    THEN UPPER(LTRIM(RTRIM(Razon_Social)))
                    ELSE UPPER(LTRIM(RTRIM(Nombre_Comercial)))
                END
            "),
        ]);
    }

    /**
     * Sin vuelta atrás: no quedó registro de cómo estaba escrito cada
     * nombre, ni de qué nombres comerciales estaban vacíos.
     */
    public function down(): void
    {
    }
};
