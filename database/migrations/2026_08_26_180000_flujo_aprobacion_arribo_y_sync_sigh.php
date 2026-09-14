<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flujo nuevo de "Rechazado -> solicitud de aprobación" + los 2 eventos
 * que ahora llegan AUTOMÁTICOS desde SIGH (En_Recepcion / Recibido), en
 * vez de marcarse a mano (ver HorarioEntregaService).
 *
 * - Horario_Entrega_Estado_Diario gana 2 columnas:
 *   - Hora_Recepcion_Real: la pone el job de sincronización (ver
 *     SincronizarEstadosSighCommand) apenas aparece el primer registro en
 *     SIGH.DocumentoInv que referencia el pedido de este proveedor.
 *   - Nro_Documento_Bc: el NroDocumentoBC que resolvió la sincronización,
 *     solo para trazabilidad/soporte (poder ver en el portal a qué
 *     documento de SIGH corresponde el cambio automático).
 *   Hora_Entregado_Real/Marcado_Entregado_Por se REUSAN para "Recibido"
 *   (ya no es Compras marcando a mano: Marcado_Entregado_Por queda NULL
 *   cuando lo puso el job, así se distingue de una marca manual vieja).
 *
 * - Tabla nueva Solicitud_Aprobacion_Arribo: cuando el Guardia ya no
 *   puede marcar arribo directo (pasaron 30 min y el horario cayó a
 *   Rechazado), en vez de bloquearlo sin más pide una aprobación acá.
 *   Solo Calidad (Valeria) la resuelve desde el portal; el aviso de que
 *   hay una solicitud nueva se manda por correo (ver
 *   SolicitudAprobacionArriboMail), no por el portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Horario_Entrega_Estado_Diario', function (Blueprint $table) {
            $table->dateTime('Hora_Recepcion_Real')->nullable()->after('Hora_Arribo_Real');
            $table->string('Nro_Documento_Bc', 50)->nullable()->after('Hora_Recepcion_Real');
        });

        Schema::create('Solicitud_Aprobacion_Arribo', function (Blueprint $table) {
            $table->id('Id_Solicitud_Aprobacion_Arribo');
            $table->unsignedBigInteger('Id_Horario_Entrega_Proveedor');
            $table->date('Fecha');
            $table->unsignedInteger('Solicitado_Por');
            $table->dateTime('Fecha_Solicitud');
            // Pendiente | Aprobada | Rechazada
            $table->string('Estado', 20)->default('Pendiente');
            $table->unsignedInteger('Resuelto_Por')->nullable();
            $table->dateTime('Fecha_Resolucion')->nullable();
            $table->string('Comentario_Resolucion', 250)->nullable();

            $table->foreign('Id_Horario_Entrega_Proveedor')
                ->references('Id_Horario_Entrega_Proveedor')->on('Horario_Entrega_Proveedor');
            $table->foreign('Solicitado_Por')->references('Id_Usuario')->on('Usuario');
            $table->foreign('Resuelto_Por')->references('Id_Usuario')->on('Usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Solicitud_Aprobacion_Arribo');

        Schema::table('Horario_Entrega_Estado_Diario', function (Blueprint $table) {
            $table->dropColumn(['Hora_Recepcion_Real', 'Nro_Documento_Bc']);
        });
    }
};
