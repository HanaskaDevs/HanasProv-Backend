<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El correo del laboratorio pasa de analisis_laboratorio@hanaska.com a
 * laboratorio@hanaska.com (pedido del usuario, 01-oct-2026).
 *
 * POR QUÉ UNA MIGRACIÓN Y NO SOLO UN REEMPLAZO EN EL CÓDIGO: el texto que
 * ve el proveedor en el home no está en el repositorio, es contenido
 * cargado en la tabla Home_Slide y editable desde Configuraciones. Tocar
 * el código no lo cambiaría, y en producción nadie se enteraría hasta que
 * un proveedor escribiera a una casilla que ya no existe.
 *
 * SE USA REPLACE Y NO SE PISA EL TEXTO ENTERO a propósito: el contenido es
 * editable, así que la redacción exacta en producción puede no ser la misma
 * que en desarrollo. Reemplazando solo la dirección, lo que alguien haya
 * reescrito alrededor se respeta.
 *
 * SE BARREN LAS CUATRO TABLAS DE CONTENIDO EDITABLE y no solo el slide del
 * home: la dirección pudo haberse pegado también en una regla del bot, en
 * un paso de la guía o en una política desde la pantalla de
 * Configuraciones. Es exactamente el caso que se pidió evitar ("en todo
 * lugar"). Las filas que no la contienen no se tocan.
 *
 * Los otros dos lugares donde vive el correo SÍ son código y van aparte:
 * el tooltip de la ficha de productos (frontend, shared/config/contactos)
 * y la guía que lee Hana (AsistenteGuiaPortal::paraProveedor).
 */
return new class extends Migration
{
    protected const VIEJO = 'analisis_laboratorio@hanaska.com';

    protected const NUEVO = 'laboratorio@hanaska.com';

    /** Tabla => columnas de texto donde puede estar la dirección. */
    protected const CAMPOS = [
        'Home_Slide' => ['Titulo', 'Descripcion'],
        'Bot_Regla' => ['Contenido'],
        'Guia_Paso' => ['Titulo', 'Texto'],
        'Politica' => ['Titulo', 'Descripcion'],
    ];

    public function up(): void
    {
        $this->reemplazar(self::VIEJO, self::NUEVO);
    }

    public function down(): void
    {
        $this->reemplazar(self::NUEVO, self::VIEJO);
    }

    protected function reemplazar(string $de, string $a): void
    {
        foreach (self::CAMPOS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                DB::table($tabla)
                    ->where($columna, 'like', '%'.$de.'%')
                    ->update([$columna => DB::raw("REPLACE({$columna}, '{$de}', '{$a}')")]);
            }
        }
    }
};
