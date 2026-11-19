<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos fuera de planificación (19-nov-2026, pedido del usuario).
 *
 * Son turnos sueltos que Compras agenda en Business Central (extensión
 * Calendario_Portal_Proveedores) dentro de unas ventanas fijas por
 * clasificación (config('portal.fuera_planificacion')), de 20, 40 o 60 min.
 * Viven en la MISMA tabla del calendario (Horario_Entrega_Proveedor), con
 * los mismos campos ("tal cual están las tablas ahorita", decisión del
 * usuario), para que el seguimiento de hoy, el Modo TV, la voz, el aviso a
 * Calidad, la recepción en bodega y el historial los traten igual que a
 * cualquier cita sin una segunda implementación. Lo que los distingue:
 *
 *  - Fecha_Fuera_Planificacion: la fecha concreta del turno. NULL = horario
 *    del calendario semanal de siempre. Con fecha, el turno vale SOLO ese
 *    día (no se repite cada semana como la plantilla).
 *  - Id_Turno_Bc: el Nº de movimiento del turno en BC, para que reenviar
 *    el mismo turno lo actualice en vez de duplicarlo.
 *
 * BC borra sus turnos cada domingo a la 1:00; aquí NO se borran (decisión
 * del usuario: "que quede historial en la base").
 *
 * Historial_Horario_Proveedor gana Fuera_Planificacion para que el reporte
 * distinga esas citas (tienen otra tolerancia: 10 min y sin aprobación).
 *
 * SQL directo, como las otras migraciones sobre estas tablas. Solo agrega
 * columnas que admiten NULL o tienen DEFAULT: nada de lo existente cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE dbo.Horario_Entrega_Proveedor ADD Fecha_Fuera_Planificacion date NULL, Id_Turno_Bc int NULL');
        DB::statement('CREATE INDEX IX_HorarioEntrega_FueraPlanificacion ON dbo.Horario_Entrega_Proveedor (Id_Empresa, Fecha_Fuera_Planificacion) WHERE Fecha_Fuera_Planificacion IS NOT NULL');

        DB::statement('ALTER TABLE dbo.Historial_Horario_Proveedor ADD Fuera_Planificacion bit NOT NULL CONSTRAINT DF_Historial_Horario_Proveedor_FueraPlan DEFAULT 0');
    }

    public function down(): void
    {
        // Se niega si ya hay turnos fuera de planificación: quitar la columna
        // los convertiría en horarios semanales que se repetirían cada semana.
        $turnos = DB::table('Horario_Entrega_Proveedor')->whereNotNull('Fecha_Fuera_Planificacion')->count();

        if ($turnos > 0) {
            throw new RuntimeException("No se revierte: hay {$turnos} turno(s) fuera de planificación en Horario_Entrega_Proveedor.");
        }

        DB::statement('ALTER TABLE dbo.Historial_Horario_Proveedor DROP CONSTRAINT DF_Historial_Horario_Proveedor_FueraPlan');
        DB::statement('ALTER TABLE dbo.Historial_Horario_Proveedor DROP COLUMN Fuera_Planificacion');

        DB::statement('DROP INDEX IX_HorarioEntrega_FueraPlanificacion ON dbo.Horario_Entrega_Proveedor');
        DB::statement('ALTER TABLE dbo.Horario_Entrega_Proveedor DROP COLUMN Fecha_Fuera_Planificacion, Id_Turno_Bc');
    }
};
