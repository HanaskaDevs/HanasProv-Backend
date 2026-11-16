<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Historial de horario por proveedor (16-nov-2026, pedido del usuario: "una
 * tabla para ver los proveedores con estado atrasado... que estén todos los
 * estados por proveedor").
 *
 * UNA FILA POR CITA: proveedor + clasificación + día en que estaba citado,
 * haya llegado o no. Es una FOTO del día: lo llena el comando
 * horarios-entrega:historial (ver HistorialHorarioProveedorService), no la
 * aplicación al marcar.
 *
 * Por qué una tabla y no una vista:
 *  - Cuando un proveedor no llega, Horario_Entrega_Estado_Diario no tiene
 *    fila; y los que llegan 30 min tarde o más quedan solo en
 *    Solicitud_Aprobacion_Arribo. "Atrasado" y "Rechazado" no se guardan en
 *    ningún lado (se calculan al vuelo).
 *  - El calendario se activa y desactiva todos los días desde BC: una vista
 *    sobre el calendario de hoy reescribiría el pasado.
 *
 * Nombres cortos a propósito (pedido del usuario): se usa desde SQL, Excel
 * o Power BI sin joins. Sin FK: es historia y debe sobrevivir a cambios del
 * calendario; los guiones de borrado de proveedores/empresas la limpian
 * igual (2026-09-17-*.sql).
 *
 * Horas en time(0): HH:MM:SS sin fracciones, legibles tal cual.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE dbo.Historial_Horario_Proveedor (
                Id                 bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_Historial_Horario_Proveedor PRIMARY KEY,
                Id_Horario         bigint        NOT NULL,
                Id_Proveedor       int           NOT NULL,
                Id_Empresa         int           NOT NULL,
                Fecha              date          NOT NULL,
                Dia                nvarchar(10)  NOT NULL,
                Empresa            nvarchar(50)  NULL,
                Cod_Proveedor      nvarchar(50)  NULL,
                Ruc                varchar(13)   NULL,
                Proveedor          nvarchar(200) NULL,
                Nombre_Comercial   nvarchar(200) NULL,
                Clasificacion      nvarchar(20)  NULL,
                Anden              nvarchar(20)  NULL,
                Hora_Programada    time(0)       NOT NULL,
                Salida_Programada  time(0)       NULL,
                Hora_Arribo        time(0)       NULL,
                Min_Atraso         int           NULL,
                Llego              bit           NOT NULL,
                Puntualidad        nvarchar(20)  NOT NULL,
                Estado             nvarchar(20)  NOT NULL,
                Aprobacion         nvarchar(10)  NULL,
                Hora_Recepcion     time(0)       NULL,
                Hora_Recibido      time(0)       NULL,
                Min_Espera         int           NULL,
                Min_Descarga       int           NULL,
                Origen             nvarchar(15)  NOT NULL,
                Actualizado        datetime      NOT NULL
            )
            SQL);

        DB::statement('CREATE UNIQUE INDEX UX_Historial_Horario_Proveedor_Horario_Fecha ON dbo.Historial_Horario_Proveedor (Id_Horario, Fecha)');
        DB::statement('CREATE INDEX IX_Historial_Horario_Proveedor_Fecha_Estado ON dbo.Historial_Horario_Proveedor (Fecha, Estado)');
        DB::statement('CREATE INDEX IX_Historial_Horario_Proveedor_Cod_Fecha ON dbo.Historial_Horario_Proveedor (Cod_Proveedor, Fecha)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS dbo.Historial_Horario_Proveedor');
    }
};
