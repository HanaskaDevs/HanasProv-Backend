<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La restricción UNIQUE original (Id_Empresa, Ruc) revienta en SQL Server
 * apenas hay MÁS DE UN proveedor "aspirante" (Ruc todavía NULL) por
 * empresa, porque a diferencia de Postgres/MySQL, SQL Server trata todos
 * los NULL como iguales dentro de un índice único compuesto. Como una
 * empresa puede tener varios proveedores invitados que todavía no
 * llenaron su Ficha (Ruc sigue en NULL hasta ese momento), el segundo
 * proveedor pendiente que se intentaba agregar chocaba con "Violación de
 * UNIQUE KEY 'UQ_Proveedor_Empresa_Ruc'".
 *
 * La solución es un índice único FILTRADO: la restricción de unicidad
 * de Ruc por empresa solo aplica a quienes YA tienen Ruc cargado. Los
 * "cascarones" con Ruc NULL dejan de competir entre sí por unicidad,
 * que es justo lo que el negocio necesita (dos proveedores de la misma
 * empresa no pueden compartir RUC, pero sí pueden coexistir varios
 * pendientes sin RUC todavía).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX UQ_Proveedor_Empresa_Ruc ON [Proveedor]');

        DB::statement(
            'CREATE UNIQUE INDEX UQ_Proveedor_Empresa_Ruc ON [Proveedor] ([Id_Empresa], [Ruc]) WHERE [Ruc] IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX UQ_Proveedor_Empresa_Ruc ON [Proveedor]');

        Schema::table('Proveedor', function (Blueprint $table) {
            $table->unique(['Id_Empresa', 'Ruc'], 'UQ_Proveedor_Empresa_Ruc');
        });
    }
};