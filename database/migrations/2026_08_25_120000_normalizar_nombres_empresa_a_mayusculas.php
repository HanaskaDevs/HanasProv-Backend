<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pasa a mayúsculas los nombres de las empresas que ya estaban cargadas.
 *
 * De ahora en adelante la normalización la hace GuardarEmpresaRequest al
 * crear y al editar; esto es para las filas que entraron antes de esa regla
 * ("Caterfood Broadliner" quedaba con mayúsculas y minúsculas mezcladas,
 * mientras "FROZENTROPIC" ya estaba en mayúsculas, y el selector del
 * encabezado mostraba los dos estilos juntos).
 *
 * UPPER() de SQL Server sobre una columna nvarchar respeta tildes y Ñ, así
 * que no hace falta traer las filas a PHP para usar mb_strtoupper.
 *
 * El down() no revierte: no se guardó cómo estaba escrito cada nombre antes,
 * y adivinar una capitalización "bonita" sería inventar datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE Empresa SET Razon_Social = UPPER(Razon_Social) WHERE Razon_Social IS NOT NULL');
        DB::statement('UPDATE Empresa SET Nombre_Comercial = UPPER(Nombre_Comercial) WHERE Nombre_Comercial IS NOT NULL');
    }

    public function down(): void
    {
        // Irreversible a propósito (ver el comentario de arriba).
    }
};
