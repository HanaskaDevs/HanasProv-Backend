<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmación de políticas al enviar la Ficha de Proveedor a revisión.
 *
 * Cuando el proveedor completa la última sección de su ficha, antes de que
 * pase a la cola de revisión del equipo, tiene que marcar que acepta las
 * Políticas de Hanaska. Esta tabla es el registro de esa aceptación:
 * quién (Id_Proveedor) y cuándo (solo la fecha, sin hora, por pedido
 * expreso del usuario).
 *
 * Es una tabla APARTE y no una columna más en Proveedor a propósito: la
 * tabla Proveedor ya arrastra muchas columnas de estado y una fila propia
 * deja la evidencia separada del dato operativo, sin tocar un esquema que
 * en parte se creó fuera de las migraciones (ver CLAUDE.md).
 *
 * Una sola fila por proveedor (UNIQUE): la aceptación vale para toda la
 * vida de la ficha, incluidas las correcciones posteriores a un rechazo.
 * Si algún día hace falta un historial, se quita el UNIQUE.
 *
 * Los tipos espejan los de la base real: Proveedor.Id_Proveedor es `int`,
 * y SQL Server exige que la columna de una FK tenga EXACTAMENTE el mismo
 * tipo que la referenciada, así que acá va integer() y no bigInteger().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Confirmacion_Ficha', function (Blueprint $table) {
            $table->increments('Id_Confirmacion_Ficha');
            $table->integer('Id_Proveedor');
            $table->date('Fecha_Confirmacion');

            $table->unique('Id_Proveedor', 'UQ_Confirmacion_Ficha_Proveedor');
            $table->foreign('Id_Proveedor', 'FK_Confirmacion_Ficha_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Confirmacion_Ficha');
    }
};
