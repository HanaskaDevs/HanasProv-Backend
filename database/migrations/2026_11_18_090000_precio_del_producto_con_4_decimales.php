<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Precio del producto con hasta 4 decimales (18-nov-2026, pedido del
 * usuario).
 *
 * Producto.Precio era DECIMAL(10,2): un costo como 0,1275 se guardaba 0,13.
 * En Business Central los costos vienen con más precisión (más de la mitad
 * de BC_Producto_Proveedor.Costo_Unitario_Directo tiene más de 2 decimales),
 * así que el portal mostraba un precio distinto del que tiene el producto.
 *
 * Se agranda la precisión junto con la escala para NO perder dígitos
 * enteros: (10,2) -> (12,4) sigue admitiendo 8 enteros, y en las
 * solicitudes de cambio de precio (12,2) -> (14,4) sigue admitiendo 10.
 * Solo agranda: ningún valor guardado se recorta ni cambia (5.00 pasa a
 * leerse 5.0000).
 *
 * Con SQL directo y no con el Schema builder, igual que las otras
 * migraciones sobre Producto: la tabla se creó fuera de las migraciones.
 * Sin índices, defaults ni checks sobre estas tres columnas (verificado
 * contra sys.default_constraints, sys.check_constraints y
 * sys.index_columns antes de escribir esto).
 *
 * Los productos que YA se habían traído de Business Central conservan su
 * precio redondeado a 2 decimales: esta migración no vuelve a leer BC.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE Producto ALTER COLUMN Precio DECIMAL(12,4) NULL');
        DB::statement('ALTER TABLE Solicitud_Cambio_Precio ALTER COLUMN Precio_Anterior DECIMAL(14,4) NOT NULL');
        DB::statement('ALTER TABLE Solicitud_Cambio_Precio ALTER COLUMN Precio_Nuevo DECIMAL(14,4) NOT NULL');
    }

    public function down(): void
    {
        // Volver a 2 decimales redondearía los precios que ya se hayan
        // guardado con 3 o 4: se aborta en vez de cambiarlos en silencio.
        $productos = DB::table('Producto')
            ->whereRaw('Precio <> ROUND(Precio, 2)')
            ->count();

        $solicitudes = DB::table('Solicitud_Cambio_Precio')
            ->whereRaw('Precio_Anterior <> ROUND(Precio_Anterior, 2) OR Precio_Nuevo <> ROUND(Precio_Nuevo, 2)')
            ->count();

        if ($productos > 0 || $solicitudes > 0) {
            throw new RuntimeException(
                "No se revierte: {$productos} producto(s) y {$solicitudes} solicitud(es) de cambio de precio "
                .'tienen precios con más de 2 decimales y se redondearían.'
            );
        }

        DB::statement('ALTER TABLE Solicitud_Cambio_Precio ALTER COLUMN Precio_Nuevo DECIMAL(12,2) NOT NULL');
        DB::statement('ALTER TABLE Solicitud_Cambio_Precio ALTER COLUMN Precio_Anterior DECIMAL(12,2) NOT NULL');
        DB::statement('ALTER TABLE Producto ALTER COLUMN Precio DECIMAL(10,2) NULL');
    }
};
