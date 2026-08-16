<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambios de ESQUEMA necesarios para las nuevas reglas de negocio de
 * documentación (pedido de Isak, agosto 2026):
 *
 * 1) Tipo_Documento.Requiere_Excepto_Quito: espejo de
 *    Requiere_Solo_Quito, pero al revés -> hoy "Permiso de
 *    funcionamiento Bomberos" se pedía en TODAS las ciudades; ahora se
 *    pide solo fuera de Quito (en Quito ya se exige LUAE en su lugar).
 *    No se reutiliza/invierte Requiere_Solo_Quito porque ambos flags
 *    pueden convivir sin problema (un tipo puede no requerir ninguno,
 *    lo normal) y así no hay que tocar el significado de una columna
 *    que ya está en uso.
 *
 * 2) Tipo_Documento_Clase_Excluida: pivote nuevo -> "esta Clase de
 *    Proveedor NO necesita este Tipo_Documento" (ej. un Productor
 *    Agrícola no necesita LUAE). No existía ninguna relación entre
 *    Clase_Proveedor y Tipo_Documento hasta ahora.
 *
 * 3) Tipo_Documento_Producto gana Permite_Multiples y
 *    Requiere_Fecha_Caducidad (ya existían en Tipo_Documento, pero acá
 *    no) -> hacen falta porque "Notificación sanitaria..." y "Hojas de
 *    seguridad" pasan a ser documentos POR PRODUCTO y necesitan esas
 *    mismas dos reglas que ya tenían a nivel proveedor.
 *
 * 4) Documento_Producto gana Fecha_Caducidad (nullable) -> hace falta
 *    para poder guardar la fecha de vencimiento de la Notificación
 *    Sanitaria de cada producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Tipo_Documento', function (Blueprint $table) {
            $table->boolean('Requiere_Excepto_Quito')->default(false)->after('Requiere_Solo_Quito');
        });

        Schema::table('Tipo_Documento_Producto', function (Blueprint $table) {
            $table->boolean('Permite_Multiples')->default(false)->after('Obligatorio');
            $table->boolean('Requiere_Fecha_Caducidad')->default(false)->after('Permite_Multiples');
        });

        Schema::table('Documento_Producto', function (Blueprint $table) {
            $table->date('Fecha_Caducidad')->nullable()->after('Id_Archivo');
        });

        Schema::create('Tipo_Documento_Clase_Excluida', function (Blueprint $table) {
            $table->id('Id_Tipo_Documento_Clase_Excluida');
            $table->unsignedBigInteger('Id_Tipo_Documento');
            $table->unsignedBigInteger('Id_Clase_Proveedor');
            $table->boolean('Activo')->default(true);

            $table->foreign('Id_Tipo_Documento')->references('Id_Tipo_Documento')->on('Tipo_Documento');
            $table->foreign('Id_Clase_Proveedor')->references('Id_Clase_Proveedor')->on('Clase_Proveedor');
            $table->unique(['Id_Tipo_Documento', 'Id_Clase_Proveedor'], 'uq_tipo_doc_clase_excluida');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Tipo_Documento_Clase_Excluida');

        Schema::table('Documento_Producto', function (Blueprint $table) {
            $table->dropColumn('Fecha_Caducidad');
        });

        Schema::table('Tipo_Documento_Producto', function (Blueprint $table) {
            $table->dropColumn(['Permite_Multiples', 'Requiere_Fecha_Caducidad']);
        });

        Schema::table('Tipo_Documento', function (Blueprint $table) {
            $table->dropColumn('Requiere_Excepto_Quito');
        });
    }
};
