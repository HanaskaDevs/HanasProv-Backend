<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag que bloquea SOLO el campo Precio de un producto mientras un
 * cambio de precio solicitado por el proveedor está pendiente de
 * aprobación (ver Solicitud_Cambio_Precio). A propósito es un campo
 * aparte de "Bloqueado" (que bloquea el producto ENTERO durante la
 * calificación inicial de ficha/documentos) -> acá el resto del
 * producto sigue disponible con normalidad, solo el precio queda de
 * solo lectura hasta que un Admin/Calidad de la empresa apruebe o
 * rechace el cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->boolean('Precio_En_Revision')->default(false)->after('Bloqueado');
        });
    }

    public function down(): void
    {
        Schema::table('Producto', function (Blueprint $table) {
            $table->dropColumn('Precio_En_Revision');
        });
    }
};
