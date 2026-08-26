<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de que al proveedor ya se le mostró el cartel "¡Ya es un proveedor
 * aprobado!" en su pantalla de Calificación.
 *
 * Hasta ahora ese cartel se dibujaba mientras el estado fuera Aprobado, o
 * sea SIEMPRE: el proveedor lo veía cada vez que entraba, meses después de
 * haber sido aprobado. Es una felicitación, no un estado permanente.
 *
 * POR QUÉ UNA COLUMNA Y NO localStorage: la marca tiene que sobrevivir al
 * cambio de navegador, al celular y a que el proveedor limpie la caché. Con
 * localStorage el cartel reaparecería en cada dispositivo nuevo.
 *
 * POR QUÉ NO SE REUSA Felicitacion_Bienvenida_Mostrada: esa columna ya la
 * consume el saludo proactivo de Hana (ver AsistenteService::
 * obtenerBienvenidaProactiva), y ese saludo la apaga al mostrarse. Si
 * compartieran la marca, el que apareciera primero taparía al otro para
 * siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->boolean('Felicitacion_Calificacion_Mostrada')
                ->default(false)
                ->after('Felicitacion_Bienvenida_Mostrada');
        });
    }

    public function down(): void
    {
        Schema::table('Proveedor', function (Blueprint $table) {
            $table->dropColumn('Felicitacion_Calificacion_Mostrada');
        });
    }
};
