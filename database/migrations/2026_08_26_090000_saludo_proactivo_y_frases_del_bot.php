<?php

use App\Modules\Asistente\Services\AsistenteService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carga el saludo proactivo de Marcia y las cinco frases motivacionales.
 *
 * Va como migración y no como seeder porque es contenido que se pidió que
 * exista, no datos de prueba: si mañana se levanta el portal en otro
 * ambiente, tiene que estar.
 *
 * TAMBIÉN CORRIGE una regla mal cargada: se había guardado con Tipo
 * 'Persona' la instrucción "cuando el usuario sea mlopez, salúdalo así". Eso
 * no podía funcionar (el saludo aparece al entrar, sin llamada al modelo) y
 * además, con el comportamiento anterior de armarPersona(), REEMPLAZABA toda
 * la personalidad del bot. Se desactiva esa fila y se reemplaza por reglas del
 * tipo correcto.
 *
 * El correo también estaba mal escrito: decía mlopez@hanska.com, sin la
 * segunda "a" de hanaska.
 */
return new class extends Migration
{
    private const FRASES = [
        'Que tengas un día tranquilo y productivo. Un paso a la vez alcanza para llegar lejos.',
        'Lo que se hace con cuidado hoy, mañana no hay que rehacerlo. ¡Buen día!',
        'No hace falta hacerlo todo: alcanza con hacer bien lo que toca hoy.',
        'Los detalles que nadie ve son los que hacen la diferencia. ¡Gracias por cuidarlos!',
        'Cada proveedor bien atendido es un problema menos la semana que viene. ¡Vamos con todo!',
    ];

    public function up(): void
    {
        // La regla vieja: se desactiva en vez de borrarla, para que quede
        // rastro de qué se había intentado configurar.
        DB::table('Bot_Regla')
            ->where('Tipo', 'Persona')
            ->where('Contenido', 'like', '%mlopez@%')
            ->update(['Activo' => 0, 'Fecha_Modificacion' => now()->format('Y-m-d\TH:i:s')]);

        DB::table('Bot_Regla')->insert([
            'Tipo' => AsistenteService::TIPO_SALUDO,
            'Palabra_Clave' => 'mlopez@hanaska.com',
            'Contenido' => 'Hola Marcita, ¿cómo estás? ¿Qué tal tu día?',
            'Orden' => 1,
            'Activo' => 1,
            'Fecha_Modificacion' => now()->format('Y-m-d\TH:i:s'),
        ]);

        foreach (self::FRASES as $orden => $frase) {
            DB::table('Bot_Regla')->insert([
                'Tipo' => AsistenteService::TIPO_FRASE,
                'Palabra_Clave' => null,
                'Contenido' => $frase,
                'Orden' => $orden + 1,
                'Activo' => 1,
                'Fecha_Modificacion' => now()->format('Y-m-d\TH:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('Bot_Regla')
            ->whereIn('Tipo', [AsistenteService::TIPO_SALUDO, AsistenteService::TIPO_FRASE])
            ->delete();

        DB::table('Bot_Regla')
            ->where('Tipo', 'Persona')
            ->where('Contenido', 'like', '%mlopez@%')
            ->update(['Activo' => 1]);
    }
};
