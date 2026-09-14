<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Asigna los códigos cortos por nombre (LIKE, no exacto, para no
 * depender de mayúsculas/tildes exactas). Si algún tipo de documento no
 * matchea ninguno de estos patrones, se queda en NULL -> el código en
 * DocumentoProveedorService/ProductoService ya contempla ese caso y usa
 * un fallback derivado de Carpeta_Slug, así que no rompe nada, solo
 * queda menos prolijo hasta que alguien le ponga el código a mano
 * (UPDATE Tipo_Documento SET Codigo_Archivo = 'XX' WHERE ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Documentación general (Tipo_Documento)
        $codigosDocumentacion = [
            'IESS' => '%iess%',
            'CG' => '%garant%',
            'NS' => '%notificaci%sanitaria%',
            'ARCSA' => '%arcsa%',
            'BOM' => '%bomberos%',
            'RUC' => '%ruc%',
            'LUAE' => '%luae%',
            'RT' => '%reglamento%trabajo%',
            'CC' => '%certificaci%calidad%',
        ];

        foreach ($codigosDocumentacion as $codigo => $patron) {
            DB::table('Tipo_Documento')
                ->where('Nombre_Documento', 'like', $patron)
                ->whereNull('Codigo_Archivo')
                ->update(['Codigo_Archivo' => $codigo]);
        }

        // Documentos de producto (Tipo_Documento_Producto)
        $codigosProducto = [
            'FT' => '%ficha%tecnica%',
            'AL' => '%an%lisis%',
            'CA' => '%al%rgenos%',
        ];

        foreach ($codigosProducto as $codigo => $patron) {
            DB::table('Tipo_Documento_Producto')
                ->where('Nombre_Documento', 'like', $patron)
                ->whereNull('Codigo_Archivo')
                ->update(['Codigo_Archivo' => $codigo]);
        }
    }

    public function down(): void
    {
        DB::table('Tipo_Documento')->update(['Codigo_Archivo' => null]);
        DB::table('Tipo_Documento_Producto')->update(['Codigo_Archivo' => null]);
    }
};