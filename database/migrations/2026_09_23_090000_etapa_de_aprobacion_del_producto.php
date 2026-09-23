<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El producto ahora se aprueba en DOS pasos (pedido del usuario,
 * 23-sep-2026):
 *
 *     proveedor envía  ->  COMPRAS revisa  ->  CALIDAD aprueba  ->  aprobado
 *
 * Compras puede aprobar (y ahí recién pasa a Calidad), rechazar para que el
 * proveedor corrija, o eliminar el producto; las dos últimas exigen una
 * observación que viaja al proveedor por correo.
 *
 * POR QUÉ UNA COLUMNA NUEVA Y NO UN ESTADO MÁS EN Estado_Calificacion:
 * agregar un valor intermedio ahí habría roto en silencio todo lo que ya
 * pregunta por 'Pendiente' / 'Aprobado' / 'Rechazado' -la calificación
 * global, el resumen de registro, los conteos del catálogo, el
 * diagnóstico de aprobación del proveedor-. Con una columna aparte,
 * Estado_Calificacion sigue significando exactamente lo mismo que antes
 * (el VEREDICTO) y esta dice en qué ESCRITORIO está parado el producto:
 *
 *   Etapa_Aprobacion = 'Compras'  -> esperando a Compras
 *   Etapa_Aprobacion = 'Calidad'  -> Compras ya lo pasó, espera a Calidad
 *   Etapa_Aprobacion = NULL       -> no está en revisión (borrador o cerrado)
 *
 * Cuando se rechaza, la columna conserva la etapa donde se rechazó: es lo
 * que permite decirle al proveedor "te lo rechazó Compras" y no un genérico.
 *
 * LOS PRODUCTOS QUE YA ESTABAN EN REVISIÓN se pasan a la etapa de Compras:
 * es el paso nuevo, y hacerlos saltar directo a Calidad dejaría a Compras
 * sin ver justamente los que están en curso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->string('Etapa_Aprobacion', 10)->nullable()->after('Estado_Calificacion');
        });

        // Los que ya estaban esperando calificación entran por Compras.
        DB::table('Producto')
            ->where('Activo', 1)
            ->where('Bloqueado', 1)
            ->where('Estado_Calificacion', 'Pendiente')
            ->update(['Etapa_Aprobacion' => 'Compras']);

        // Y los ya rechazados quedan marcados como rechazados en Calidad,
        // que es quien los rechazó cuando Compras todavía no existía en el
        // circuito. Si no, el proveedor vería "rechazado por Compras" sobre
        // decisiones que Compras nunca tomó.
        DB::table('Producto')
            ->where('Activo', 1)
            ->where('Estado_Calificacion', 'Rechazado')
            ->update(['Etapa_Aprobacion' => 'Calidad']);
    }

    public function down(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->dropColumn('Etapa_Aprobacion');
        });
    }
};
