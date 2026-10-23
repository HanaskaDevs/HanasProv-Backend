<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El "Análisis de producto" (AP) pasa a pedir fecha de caducidad (pedido
 * del usuario, 23-oct-2026), igual que la Notificación sanitaria.
 *
 * Lo que cambia:
 *  - el proveedor tiene que indicar la fecha al subir un análisis nuevo
 *    (ProductoService::subirDocumento ya lo exige para los tipos con
 *    Requiere_Fecha_Caducidad);
 *  - los análisis que ya estaban cargados quedan SIN fecha, y Calidad la
 *    completa desde la calificación (CalificacionProveedorService::
 *    asignarFechaCaducidadDocumentoProducto). Sin ella no se puede
 *    aprobar el producto.
 *
 * Se busca por Codigo_Archivo y no por nombre: el nombre se edita desde el
 * catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('Tipo_Documento_Producto')
            ->where('Codigo_Archivo', 'AP')
            ->update(['Requiere_Fecha_Caducidad' => true]);
    }

    public function down(): void
    {
        DB::table('Tipo_Documento_Producto')
            ->where('Codigo_Archivo', 'AP')
            ->update(['Requiere_Fecha_Caducidad' => false]);
    }
};
