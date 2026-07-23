<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documenta la ampliación de la tabla Proveedor para la Ficha de Proveedor
 * (Sección 1: Información del Proveedor). Estas columnas ya fueron aplicadas
 * manualmente en el servidor SQL Server vía script -> esta migration solo
 * queda como historial de versionado, se marca como ya ejecutada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->string('Clase_Contribuyente', 50)->nullable()->after('Ruc');
            $table->string('Ciudad', 50)->nullable()->after('Direccion');
            $table->string('Pagina_Web', 300)->nullable()->after('Ciudad');
            $table->string('Representante_Legal', 100)->nullable()->after('Longitud');
            $table->string('Correo_Representante', 200)->nullable()->after('Representante_Legal');
            $table->string('Telefono_Representante', 10)->nullable()->after('Correo_Representante');
            $table->string('Contacto_Venta', 100)->nullable()->after('Telefono_Representante');
            $table->string('Correo_Venta', 200)->nullable()->after('Contacto_Venta');
            $table->string('Telefono_Contacto_Venta', 10)->nullable()->after('Correo_Venta');
            $table->string('Contacto_Calidad', 100)->nullable()->after('Telefono_Contacto_Venta');
            $table->string('Correo_Calidad', 200)->nullable()->after('Contacto_Calidad');
            $table->string('Telefono_Contacto_Calidad', 10)->nullable()->after('Correo_Calidad');
            $table->string('Contacto_Contabilidad', 100)->nullable()->after('Telefono_Contacto_Calidad');
            $table->string('Correo_Contabilidad', 200)->nullable()->after('Contacto_Contabilidad');
            $table->string('Telefono_Contabilidad', 200)->nullable()->after('Correo_Contabilidad');
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn([
                'Clase_Contribuyente', 'Ciudad', 'Pagina_Web', 'Representante_Legal',
                'Correo_Representante', 'Telefono_Representante', 'Contacto_Venta',
                'Correo_Venta', 'Telefono_Contacto_Venta', 'Contacto_Calidad',
                'Correo_Calidad', 'Telefono_Contacto_Calidad', 'Contacto_Contabilidad',
                'Correo_Contabilidad', 'Telefono_Contabilidad',
            ]);
        });
    }
};
