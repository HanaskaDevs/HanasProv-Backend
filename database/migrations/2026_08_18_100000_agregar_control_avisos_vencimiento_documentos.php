<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control de los avisos de vencimiento de documentos.
 *
 * Documento_Proveedor ya tenía Notificacion_Enviada (bit), pero un booleano
 * solo alcanza para "¿ya avisé alguna vez?". El pedido es distinto: primer
 * aviso 30 días antes del vencimiento y DESPUÉS UN REAVISO POR CADA SEMANA
 * que pasa -> para eso hay que saber CUÁNDO fue el último aviso, no solo si
 * hubo uno.
 *
 * Notificacion_Enviada se deja como está (no se borra) para no romper nada
 * que la lea; el comando nuevo trabaja con Fecha_Ultima_Notificacion y la
 * mantiene en true por compatibilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Documento_Proveedor', function (Blueprint $table) {
            $table->dateTime('Fecha_Ultima_Notificacion')->nullable()->after('Notificacion_Enviada');
        });
    }

    public function down(): void
    {
        Schema::table('Documento_Proveedor', function (Blueprint $table) {
            $table->dropColumn('Fecha_Ultima_Notificacion');
        });
    }
};
