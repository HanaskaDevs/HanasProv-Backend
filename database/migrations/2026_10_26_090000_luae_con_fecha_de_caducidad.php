<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La LUAE pasa a pedir fecha de caducidad (pedido del usuario, 26-oct-2026),
 * igual que el Permiso de funcionamiento ARCSA. Solo afecta a los
 * proveedores a los que se les pide LUAE (los de Quito, salvo la Clase
 * "Productor Agrícola"): a los demás no se les lista el documento.
 *
 * Lo que cambia:
 *  - el proveedor tiene que indicar la fecha al subir o reemplazar la LUAE
 *    (DocumentoProveedorService ya lo exige para los tipos con
 *    Requiere_Fecha_Caducidad);
 *  - las LUAE que ya estaban cargadas quedan SIN fecha. Al aprobarlas, quien
 *    califica (Calidad, Admin o Sistemas) tiene que escribirla antes; a las
 *    ya aprobadas se la escribe Admin o Calidad (ver
 *    CalificacionProveedorService::asignarFechaCaducidadDocumentoProveedor).
 *    A nadie se lo bloquea por no tenerla.
 *
 * Al escribirla (26-oct-2026) había 23 LUAE activas en producción, ninguna
 * con fecha: 22 sin calificar y 1 aprobada.
 *
 * Se busca por Codigo_Archivo y no por nombre: el nombre se edita desde el
 * catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('Tipo_Documento')
            ->where('Codigo_Archivo', 'LUAE')
            ->update(['Requiere_Fecha_Caducidad' => true]);
    }

    public function down(): void
    {
        DB::table('Tipo_Documento')
            ->where('Codigo_Archivo', 'LUAE')
            ->update(['Requiere_Fecha_Caducidad' => false]);
    }
};
