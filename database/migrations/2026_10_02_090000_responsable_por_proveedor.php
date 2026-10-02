<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quién le escribe el proveedor cuando tiene una duda (pedido del
 * usuario, 02-oct-2026). Hanaska tiene una persona asignada por proveedor;
 * hasta ahora eso vivía en un Excel y el proveedor no tenía forma de
 * saberlo.
 *
 * DOS TABLAS Y NO UNA COLUMNA EN Proveedor, por tres razones:
 *
 *  1. La asignación se carga por CÓDIGO DE PROVEEDOR DE BC, no por un id
 *     del portal. El archivo de origen trae proveedores que todavía no
 *     tienen cuenta acá, y tienen que poder cargarse igual: el día que se
 *     activen, ya los está esperando su responsable.
 *  2. Los responsables son pocos (cuatro) y se repiten en cientos de
 *     filas. Con una tabla aparte, cambiarle el teléfono a alguien es una
 *     edición y no un UPDATE masivo —y no hay forma de que queden dos
 *     teléfonos distintos para la misma persona—.
 *  3. Proveedor ya arrastra 50 columnas, varias creadas fuera de las
 *     migraciones (ver CLAUDE.md). No le sumamos otra.
 *
 * Los tipos espejan los de la base real: Empresa.Id_Empresa es `int` y
 * Proveedor.Nro_Proveedor_BC es varchar(20). SQL Server exige que la
 * columna de una FK tenga EXACTAMENTE el mismo tipo que la referenciada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- La persona ---
        Schema::create('Responsable', function (Blueprint $table) {
            $table->increments('Id_Responsable');
            $table->string('Nombre', 150);
            $table->string('Correo', 200);
            $table->string('Telefono', 20)->nullable();
            $table->boolean('Activo')->default(true);
            $table->dateTime('Fecha_Creacion')->nullable();
            $table->integer('Creado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();
            $table->integer('Modificado_Por')->nullable();

            // El correo identifica a la persona: es la columna con la que
            // el archivo de asignaciones la referencia, así que no puede
            // haber dos filas con el mismo.
            $table->unique('Correo', 'UQ_Responsable_Correo');
        });

        // --- La asignación: este código de BC es de esta persona ---
        Schema::create('Responsable_Proveedor', function (Blueprint $table) {
            $table->increments('Id_Responsable_Proveedor');
            $table->integer('Id_Empresa');
            $table->string('Nro_Proveedor_BC', 20);
            $table->integer('Id_Responsable');
            $table->dateTime('Fecha_Creacion')->nullable();
            $table->integer('Creado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();
            $table->integer('Modificado_Por')->nullable();

            // VA CON LA EMPRESA: los códigos de BC (PROV-00000XX) los
            // numera cada compañía de BC por su cuenta, así que el mismo
            // código puede ser de dos proveedores distintos en dos
            // empresas distintas. Sin la empresa en la clave, importar la
            // segunda empresa le cambiaría el responsable a la primera.
            $table->unique(['Id_Empresa', 'Nro_Proveedor_BC'], 'UQ_Responsable_Proveedor_Empresa_Bc');

            $table->foreign('Id_Empresa', 'FK_Responsable_Proveedor_Empresa')
                ->references('Id_Empresa')->on('Empresa');
            $table->foreign('Id_Responsable', 'FK_Responsable_Proveedor_Responsable')
                ->references('Id_Responsable')->on('Responsable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Responsable_Proveedor');
        Schema::dropIfExists('Responsable');
    }
};
