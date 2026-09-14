<?php

use App\Modules\Asistente\Services\AsistenteService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Saca los signos de pregunta sueltos que quedaron al final de las frases del
 * bot.
 *
 * QUÉ PASÓ: las frases se sembraron con un emoji al final. Bot_Regla.Contenido
 * es varchar, no nvarchar, así que SQL Server no puede guardar esos caracteres
 * y los reemplaza por un '?' literal al insertar. Resultado: al usuario le
 * llegaba "¡Buen día! ?" o "...con todo! ??", que se ve peor que no tener
 * emoji. Las dos migraciones que las siembran ya quedaron sin emoji, así que un
 * ambiente nuevo nace limpio; esta arregla los datos de los ambientes donde ya
 * corrieron.
 *
 * SI ALGÚN DÍA SE QUIEREN EMOJIS de verdad, hay que pasar la columna a
 * nvarchar (y usar literales N'...' al insertar). Es un cambio de esquema que
 * no se hace acá.
 *
 * SOLO TOCA LAS FILAS DE TIPO 'Frase'. La regla de saludo termina en '¿Qué tal
 * tu día?' y ese signo de pregunta sí es parte del texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        $frases = DB::table('Bot_Regla')
            ->where('Tipo', AsistenteService::TIPO_FRASE)
            ->get(['Id_Bot_Regla', 'Contenido']);

        foreach ($frases as $frase) {
            // rtrim de espacios y '?' del final. Ninguna de las frases
            // sembradas es una pregunta, así que no hay nada legítimo que
            // recortar; si mañana se carga una que termine en '?', esta
            // migración ya corrió y no la vuelve a tocar.
            $limpio = rtrim($frase->Contenido, " ?\u{00A0}");

            if ($limpio === $frase->Contenido || $limpio === '') {
                continue;
            }

            DB::table('Bot_Regla')
                ->where('Id_Bot_Regla', $frase->Id_Bot_Regla)
                ->update([
                    'Contenido' => $limpio,
                    'Fecha_Modificacion' => now()->format('Y-m-d\TH:i:s'),
                ]);
        }
    }

    public function down(): void
    {
        // No hay vuelta atrás: el emoji original no se puede reconstruir (la
        // columna no lo admite) y devolver los '?' sería restaurar el defecto.
    }
};
