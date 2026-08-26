<?php

use App\Modules\Asistente\Services\AsistenteService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Completa el banco de frases motivacionales de Hana hasta diez.
 *
 * La migración anterior (2026_08_26_090000) cargó cinco. Se pidieron unas
 * diez, y ahora la frase se elige AL AZAR en cada inicio de sesión (antes
 * rotaba por día del año), así que con cinco la repetición se notaba: con
 * diez, la probabilidad de ver dos veces la misma en dos logins seguidos baja
 * de 1 en 5 a 1 en 10.
 *
 * Va en una migración nueva y no editando la anterior porque esa ya corrió en
 * este ambiente: editarla no la volvería a ejecutar.
 *
 * Tono: cercano y de trabajo, no de frase de calendario. Quien las lee es
 * gente de administración, compras y calidad entrando al portal a las siete de
 * la mañana.
 */
return new class extends Migration
{
    private const FRASES = [
        'Un día a la vez, y hoy es el que cuenta. ¡Buen inicio!',
        'Lo urgente grita, lo importante espera. Hoy dale su turno a lo importante.',
        'Preguntar a tiempo ahorra rehacer después. Acá estoy si te sirve.',
        'La constancia hace más que la prisa. Vas muy bien.',
        'Cerrar bien un pendiente vale más que empezar tres. ¡A por eso!',
    ];

    public function up(): void
    {
        // Se parte del Orden más alto que ya exista para no pisar el de las
        // frases anteriores. El orden hoy no decide cuál sale (la elección es
        // al azar), pero sigue siendo el criterio con el que Sistemas las lee
        // y ordena en Configuraciones.
        $ultimoOrden = (int) DB::table('Bot_Regla')
            ->where('Tipo', AsistenteService::TIPO_FRASE)
            ->max('Orden');

        foreach (self::FRASES as $i => $frase) {
            // Idempotente: si la migración se corre en un ambiente donde la
            // frase ya está cargada a mano, no se duplica.
            $yaEsta = DB::table('Bot_Regla')
                ->where('Tipo', AsistenteService::TIPO_FRASE)
                ->where('Contenido', $frase)
                ->exists();

            if ($yaEsta) {
                continue;
            }

            DB::table('Bot_Regla')->insert([
                'Tipo' => AsistenteService::TIPO_FRASE,
                'Palabra_Clave' => null,
                'Contenido' => $frase,
                'Orden' => $ultimoOrden + $i + 1,
                'Activo' => 1,
                'Fecha_Modificacion' => now()->format('Y-m-d\TH:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('Bot_Regla')
            ->where('Tipo', AsistenteService::TIPO_FRASE)
            ->whereIn('Contenido', self::FRASES)
            ->delete();
    }
};
