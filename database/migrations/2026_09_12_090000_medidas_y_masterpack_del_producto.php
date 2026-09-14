<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Medidas físicas del producto y del masterpack (pedido del usuario,
 * 12-sep-2026).
 *
 * QUÉ ES UN MASTERPACK: la caja en la que viene agrupado el producto. El
 * portal ya guardaba cuántas unidades trae (Unidad_Por_Caja); lo que
 * faltaba eran SUS MEDIDAS, que es lo que necesita logística para calcular
 * cuántas cajas entran en un pallet o en un camión.
 *
 * TODO EN CENTÍMETROS. Se elige una sola unidad y se escribe en la
 * etiqueta del formulario: mezclar cm y m en un mismo grupo de campos es
 * la forma más rápida de que alguien cargue 1.8 donde iban 180.
 *
 * LAS 6 MEDIDAS SON OPCIONALES. Hoy hay 300 productos cargados sin ellas;
 * exigirlas dejaría a todos esos sin poder editarse hasta completarlas.
 *
 * Contenido_Paquete solo aplica cuando la unidad de presentación es
 * "Paquete" (ahí sí es obligatorio, lo valida GuardarProductoRequest):
 * un paquete sin decir cuánto trae adentro no significa nada. Ningún
 * producto usa hoy esa unidad, así que no deja a nadie trabado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            // Cuánto trae el paquete. Solo se llena si la unidad de
            // presentación es "Paquete".
            $table->integer('Contenido_Paquete')->nullable()->after('Unidad_Por_Caja');

            // decimal(8,2): hasta 999.999,99 cm sobra para cualquier caja,
            // y 2 decimales alcanzan para medidas de empaque (nadie mide
            // un masterpack en décimas de milímetro).
            $table->decimal('Masterpack_Largo_Cm', 8, 2)->nullable()->after('Contenido_Paquete');
            $table->decimal('Masterpack_Ancho_Cm', 8, 2)->nullable()->after('Masterpack_Largo_Cm');
            $table->decimal('Masterpack_Alto_Cm', 8, 2)->nullable()->after('Masterpack_Ancho_Cm');

            $table->decimal('Unidad_Largo_Cm', 8, 2)->nullable()->after('Masterpack_Alto_Cm');
            $table->decimal('Unidad_Ancho_Cm', 8, 2)->nullable()->after('Unidad_Largo_Cm');
            $table->decimal('Unidad_Alto_Cm', 8, 2)->nullable()->after('Unidad_Ancho_Cm');
        });

        /*
         * Volumen pasa de decimal(10,3) a decimal(12,6).
         *
         * Desde ahora NO se escribe a mano: se calcula de largo x ancho x
         * alto de la UNIDAD (ver ProductoService::volumenDeLaUnidad). Y ahí
         * 3 decimales no alcanzan: una caja de 10 x 5 x 3 cm son 150 cm³ =
         * 0,00015 m³, que con decimal(10,3) se guardaba como 0.000 -> todos
         * los productos chicos habrían quedado con volumen cero.
         */
        Schema::table('Producto', function (Blueprint $table) {
            $table->decimal('Volumen', 12, 6)->nullable()->change();
        });

        /*
         * "Litro" faltaba en el catálogo de unidades (pedido del usuario:
         * Kilogramo, Litro, Unidad, Paquete).
         *
         * updateOrInsert y no insert a secas: esta migración tiene que
         * poder correrse en un entorno donde alguien ya lo haya agregado a
         * mano, sin dejar la unidad duplicada.
         *
         * OJO: Business Central solo maneja UN / KG / PK (ver
         * ProductoService::MAPA_UNIDAD_BC), así que un producto que baje de
         * BC nunca va a venir como Litro; es una unidad del portal, para lo
         * que el proveedor carga a mano.
         */
        DB::table('Unidad_Presentacion')->updateOrInsert(
            ['Nombre_Unidad' => 'Litro'],
            ['Activo' => 1]
        );
    }

    public function down(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->dropColumn([
                'Contenido_Paquete',
                'Masterpack_Largo_Cm',
                'Masterpack_Ancho_Cm',
                'Masterpack_Alto_Cm',
                'Unidad_Largo_Cm',
                'Unidad_Ancho_Cm',
                'Unidad_Alto_Cm',
            ]);
        });

        Schema::table('Producto', function (Blueprint $table) {
            $table->decimal('Volumen', 10, 3)->nullable()->change();
        });

        // "Litro" NO se borra: si algún producto quedó con esa unidad, la
        // FK lo impediría, y perder el dato sería peor que dejar una fila
        // de catálogo de más.
    }
};
