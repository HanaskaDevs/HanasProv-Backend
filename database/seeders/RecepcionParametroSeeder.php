<?php

namespace Database\Seeders;

use App\Modules\Auditorias\Models\RecepcionParametro;
use Illuminate\Database\Seeder;

/**
 * Los 13 parámetros del formulario oficial FGH04.15.05-1 ("EVALUACIÓN DE
 * CALIDAD DEL PROVEEDOR"), con el puntaje EXACTO de la hoja. La suma da
 * 200, que es el "Total Posible" impreso en el formulario -> hay un test
 * que lo verifica, para que nadie cambie un puntaje sin darse cuenta de
 * que rompe el total.
 *
 * Idempotente: updateOrCreate por Orden, así se puede correr de nuevo para
 * corregir un texto o un puntaje sin duplicar filas ni perder las
 * calificaciones ya hechas (esas guardan su propio puntaje, ver el
 * comentario en la migración).
 *
 * OJO con los textos: se corrigieron erratas de tipeo de la hoja original
 * ("intalaciones" -> "instalaciones", "estan integros" -> "están
 * íntegros", "Congelacion" -> "Congelación"), porque esto se muestra en
 * pantalla. El contenido y los puntajes no se tocaron. Si el sistema de
 * calidad exige que el texto coincida letra por letra con el documento
 * controlado, se revierte solo acá.
 *
 * "Puntualidad de Entrega" es el único parámetro que no se responde
 * "Si/No" -> sus dos opciones son las del formulario. Por eso las
 * etiquetas son columnas y no texto fijo en el frontend.
 */
class RecepcionParametroSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: float, 2?: string, 3?: string}> */
    private const PARAMETROS = [
        ['Productos cumplen con las especificaciones de la ficha técnica.', 30],
        ['Proveedor usa el uniforme y los EPPs necesarios para ingreso al área (usa mandil o uniforme, usa gorra o cofia, botas punta de acero, casco, etc.).', 15],
        ['El proveedor cumple con las normas de Higiene Personal y Comportamiento (no usa joyas, se lava las manos, no fuma, etc.).', 15],
        ['El proveedor no interviene con los equipos e instalaciones de la empresa, respeta las áreas de descarga y no ingresa a las áreas restringidas.', 15],
        ['El transporte del proveedor es adecuado y limpio.', 15],
        ['Materias primas o productos están libres de plagas o elementos extraños.', 15],
        ['Los empaques y embalajes están íntegros, no aplastados y protegen al producto.', 10],
        ['Puntualidad de Entrega.', 15, 'En horario con max. 15 min de retraso.', 'Más de 15 min. de retraso.'],
        ['Proveedor tiene capacidad de entregar con frecuencia requerida.', 10],
        ['Proveedores cumplen con la cantidad del pedido.', 15],
        ['Proveedor limpia el área antes de retirarse del predio. Los desechos se descartan en fundas y/o se llevan consigo.', 15],
        ['Proveedor no utiliza los recursos de Hanaska (electricidad, agua).', 15],
        ['El producto cumple los rangos de temperatura requeridos para los productos que apliquen. Refrigeración max. 7 °C, Congelación max. -12 °C.', 15],
    ];

    /** Total impreso en el formulario -> lo usa el test de consistencia. */
    public const PUNTAJE_TOTAL_ESPERADO = 200;

    public function run(): void
    {
        foreach (self::PARAMETROS as $indice => $parametro) {
            RecepcionParametro::updateOrCreate(
                ['Orden' => $indice + 1],
                [
                    'Descripcion' => $parametro[0],
                    'Puntaje' => $parametro[1],
                    'Etiqueta_Afirmativa' => $parametro[2] ?? 'Si',
                    'Etiqueta_Negativa' => $parametro[3] ?? 'No',
                    'Activo' => true,
                ]
            );
        }
    }
}
