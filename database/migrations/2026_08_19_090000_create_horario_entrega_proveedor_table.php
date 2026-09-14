<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendario de Horarios de Entrega de Proveedores (pedido por Compras a
 * partir de los Excel de Fruver / Perecibles / No Perecibles que ya
 * manejaban a mano).
 *
 * Una sola tabla para las 3 clasificaciones (Clasificacion), en vez de 3
 * tablas iguales: el CRUD en el front ya distingue la clasificación por la
 * pestaña que se usó (ver pedido del usuario), así que acá alcanza con una
 * columna. Tiempo_Preparacion_Min solo aplica a Fruver (arribo -> inicio de
 * recepción); Tiempo_Permanencia_Min lo usan las 3: en Fruver es el tramo
 * "inicio de recepción -> salida", en Perecibles/No Perecibles es el único
 * tramo "llegada -> salida" que manejan esos Excel.
 *
 * Id_Empresa e Id_Proveedor van con unsignedInteger (no unsignedBigInteger)
 * a propósito: Empresa.Id_Empresa y Proveedor.Id_Proveedor ya existen como
 * INT en la base real (ver Auditoria.Id_Empresa / Id_Proveedor, mismo
 * criterio) -> con BIGINT la FK falla igual que pasó con
 * Tipo_Auditoria_Clase.Id_Clase_Proveedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Horario_Entrega_Proveedor', function (Blueprint $table) {
            $table->id('Id_Horario_Entrega_Proveedor');
            $table->unsignedInteger('Id_Empresa');
            $table->unsignedInteger('Id_Proveedor');

            // Perecibles | No_Perecibles | Fruver -> ver constantes en el modelo.
            $table->string('Clasificacion', 20);

            // Lunes .. Domingo, en texto: es lo que muestran los Excel y lo
            // que se necesita mostrar tal cual en el calendario/Modo TV.
            $table->string('Dia_Entrega', 15);

            // Andén A/B/C (Perecibles/No Perecibles) o Puerta A/B (Fruver).
            $table->string('Anden_Puerta', 20)->nullable();

            // String ("HH:MM") y no TIME a propósito: el driver sqlsrv
            // devuelve las columnas TIME como objeto \DateTime en vez de
            // texto plano, lo que complica serializar/comparar sin castear
            // en cada lectura. Como acá solo se necesita mostrar y ordenar
            // la hora (nunca sumarle duraciones en SQL), texto plano
            // validado como H:i alcanza y evita esa ambigüedad.
            $table->string('Hora_Llegada', 5);
            $table->unsignedInteger('Tiempo_Preparacion_Min')->nullable();
            $table->unsignedInteger('Tiempo_Permanencia_Min')->nullable();
            $table->string('Hora_Salida', 5)->nullable();

            $table->boolean('Activo')->default(true);
            $table->unsignedInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->nullable();
            $table->unsignedInteger('Modificado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->foreign('Id_Empresa', 'FK_HorarioEntrega_Empresa')
                ->references('Id_Empresa')->on('Empresa');
            $table->foreign('Id_Proveedor', 'FK_HorarioEntrega_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');

            $table->index(['Id_Empresa', 'Clasificacion', 'Dia_Entrega'], 'IX_HorarioEntrega_Empresa_Clase_Dia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Horario_Entrega_Proveedor');
    }
};
