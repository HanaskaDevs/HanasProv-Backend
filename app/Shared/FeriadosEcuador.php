<?php

namespace App\Shared;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Feriados nacionales del Ecuador, para saber si una fecha es día laborable.
 *
 * POR QUÉ CALCULARLOS Y NO GUARDARLOS EN UNA TABLA: los feriados fijos y los
 * que dependen de Pascua se derivan del año con certeza, y una tabla habría
 * que llenarla a mano cada diciembre. Lo que NO se puede derivar (los puentes
 * que el Ejecutivo mueve por decreto, o un feriado local del cantón) se
 * agrega por configuración, sin tocar código: ver config/portal.php ->
 * feriados_adicionales.
 *
 * OJO CON LA LEY DE OPTIMIZACIÓN DE FERIADOS: cuando un feriado cae martes,
 * miércoles o jueves, el Ejecutivo suele trasladar el descanso al viernes o
 * al lunes. Ese traslado NO se intenta adivinar acá, porque no sigue una
 * regla única y se publica cada año por decreto. La fecha REAL del feriado sí
 * está, y el traslado se agrega en la configuración cuando se publica. El
 * efecto de no adivinarlo es benigno: como mucho se agenda una recepción un
 * día que resultó ser puente, y el corrimiento a día hábil de
 * AgendaRecepcionService lo resuelve en cuanto se cargue la fecha.
 */
class FeriadosEcuador
{
    /**
     * Feriados nacionales de fecha fija, como [mes, día].
     *
     * No incluye el 24 de julio: el natalicio de Simón Bolívar no es feriado
     * nacional en Ecuador (sí lo es en otros países de la región, y es un
     * error común meterlo acá).
     */
    private const FIJOS = [
        [1, 1],   // Año Nuevo
        [5, 1],   // Día del Trabajo
        [5, 24],  // Batalla del Pichincha
        [8, 10],  // Primer Grito de Independencia
        [10, 9],  // Independencia de Guayaquil
        [11, 2],  // Día de los Difuntos
        [11, 3],  // Independencia de Cuenca
        [12, 25], // Navidad
    ];

    /** Cache por año: esto se consulta en bucle al armar la agenda. */
    private static array $cache = [];

    /**
     * ¿Es día laborable? Ni sábado, ni domingo, ni feriado.
     */
    public function esLaborable(CarbonInterface $fecha): bool
    {
        return ! $fecha->isWeekend() && ! $this->esFeriado($fecha);
    }

    public function esFeriado(CarbonInterface $fecha): bool
    {
        return in_array(
            $fecha->format('Y-m-d'),
            $this->delAnio((int) $fecha->year),
            true
        );
    }

    /**
     * Todos los feriados de un año, en formato Y-m-d.
     *
     * @return list<string>
     */
    public function delAnio(int $anio): array
    {
        if (isset(self::$cache[$anio])) {
            return self::$cache[$anio];
        }

        $fechas = [];

        foreach (self::FIJOS as [$mes, $dia]) {
            $fechas[] = Carbon::createFromDate($anio, $mes, $dia)->format('Y-m-d');
        }

        // Móviles, derivados del Domingo de Pascua.
        $pascua = $this->domingoDePascua($anio);
        $fechas[] = $pascua->copy()->subDays(2)->format('Y-m-d');  // Viernes Santo
        // Carnaval: lunes y martes previos al Miércoles de Ceniza, que cae 46
        // días antes de Pascua.
        $miercolesDeCeniza = $pascua->copy()->subDays(46);
        $fechas[] = $miercolesDeCeniza->copy()->subDays(2)->format('Y-m-d'); // lunes
        $fechas[] = $miercolesDeCeniza->copy()->subDay()->format('Y-m-d');   // martes

        // Puentes por decreto y feriados locales, desde configuración.
        foreach ((array) config('portal.feriados_adicionales', []) as $extra) {
            $fechas[] = Carbon::parse($extra)->format('Y-m-d');
        }

        $fechas = array_values(array_unique($fechas));
        sort($fechas);

        return self::$cache[$anio] = $fechas;
    }

    /**
     * Domingo de Pascua por el algoritmo gregoriano anónimo (Meeus/Jones/
     * Butcher). Es aritmética pura, sin tablas ni dependencias.
     */
    public function domingoDePascua(int $anio): Carbon
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);

        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::createFromDate($anio, $mes, $dia)->startOfDay();
    }

    /** Limpia el cache. Solo para los tests, que mueven feriados por config. */
    public static function olvidarCache(): void
    {
        self::$cache = [];
    }
}
