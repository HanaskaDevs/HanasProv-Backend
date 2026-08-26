<?php

namespace Tests\Feature\Asistente;

use App\Modules\Asistente\Services\AsistenteService;
use App\Modules\Configuraciones\Models\BotRegla;
use Tests\TestCase;

/**
 * Saludo proactivo configurable: el que aparece al entrar al portal, sin que
 * el usuario escriba nada.
 *
 * Se implementó en PHP y no como instrucción de prompt porque en ese momento
 * no hay conversación ni llamada al modelo: una regla del tipo "cuando entre
 * tal usuario, salúdalo así" no tenía forma de ejecutarse. Se intentó y no
 * pasaba nada.
 */
class SaludoProactivoTest extends TestCase
{
    private function servicio(): AsistenteService
    {
        return app(AsistenteService::class);
    }

    private function reglaSaludo(?string $correo, string $texto, int $orden = 1): void
    {
        BotRegla::create([
            'Tipo' => AsistenteService::TIPO_SALUDO,
            'Palabra_Clave' => $correo,
            'Contenido' => $texto,
            'Orden' => $orden,
            'Activo' => 1,
        ]);
    }

    public function test_saluda_al_usuario_que_tiene_regla(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa, 'Admin');

        $this->reglaSaludo($usuario->Email, 'Hola che, ¿cómo va?');

        $saludo = $this->servicio()->obtenerBienvenidaProactiva($usuario, $empresa->Id_Empresa);

        $this->assertNotNull($saludo);
        $this->assertStringContainsString('Hola che', $saludo);
    }

    public function test_no_saluda_a_quien_no_tiene_regla(): void
    {
        $empresa = $this->crearEmpresa();
        $conRegla = $this->crearUsuarioInterno($empresa, 'Admin');
        $sinRegla = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->reglaSaludo($conRegla->Email, 'Solo para este.');

        $this->assertNull(
            $this->servicio()->obtenerBienvenidaProactiva($sinRegla, $empresa->Id_Empresa),
            'La regla es de un correo puntual: nadie más debe recibirla.'
        );
    }

    /** El correo se compara sin distinguir mayúsculas ni espacios sobrantes. */
    public function test_el_correo_se_compara_sin_distinguir_mayusculas(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa, 'Admin');

        $this->reglaSaludo(' '.mb_strtoupper($usuario->Email).' ', 'Igual te encuentro.');

        $this->assertNotNull($this->servicio()->obtenerBienvenidaProactiva($usuario, $empresa->Id_Empresa));
    }

    /** Una regla con '*' aplica a todos, pero la del correo exacto gana. */
    public function test_la_regla_exacta_gana_sobre_el_comodin(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa, 'Admin');

        $this->reglaSaludo('*', 'Saludo genérico.', orden: 1);
        $this->reglaSaludo($usuario->Email, 'Saludo personal.', orden: 9);

        $saludo = $this->servicio()->obtenerBienvenidaProactiva($usuario, $empresa->Id_Empresa);

        $this->assertStringContainsString('Saludo personal', $saludo);
        $this->assertStringNotContainsString('genérico', $saludo);
    }

    public function test_el_saludo_incluye_la_frase_del_dia(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa, 'Admin');

        $this->reglaSaludo($usuario->Email, 'Buen día.');

        // Las frases sembradas por migración ya están; alcanza comprobar que
        // el saludo trae algo más que el texto de la regla.
        $saludo = $this->servicio()->obtenerBienvenidaProactiva($usuario, $empresa->Id_Empresa);

        $this->assertStringContainsString('Buen día.', $saludo);
        $this->assertGreaterThan(
            strlen('Buen día.') + 10,
            strlen($saludo),
            'Debería venir la frase del día pegada al saludo.'
        );
    }

    /**
     * La frase se elige AL AZAR entre las cargadas.
     *
     * Antes rotaba por día del año y este test afirmaba lo contrario (la
     * misma frase toda la jornada). Se pidió el cambio justamente porque
     * llegaba siempre la misma; el saludo ahora sale una sola vez por inicio
     * de sesión, así que "al azar" no significa una frase nueva en cada
     * recarga.
     *
     * Se comprueba con muchas llamadas y no con dos: dos sorteos seguidos
     * pueden dar la misma frase por pura casualidad, y un test que falla una
     * vez cada diez veces no sirve de nada. Con 30 sorteos sobre 10 frases,
     * que salgan todas iguales es del orden de 10^-29.
     */
    public function test_la_frase_se_elige_al_azar_entre_las_cargadas(): void
    {
        $empresa = $this->crearEmpresa();
        $usuario = $this->crearUsuarioInterno($empresa, 'Admin');

        $this->reglaSaludo($usuario->Email, 'Hola.');

        $frases = collect(range(1, 30))
            ->map(function () use ($usuario, $empresa): string {
                $saludo = $this->servicio()->obtenerBienvenidaProactiva($usuario, $empresa->Id_Empresa);

                return trim(explode("\n\n", $saludo)[1] ?? '');
            })
            ->unique();

        $this->assertGreaterThan(
            1,
            $frases->count(),
            'En 30 saludos tiene que haber salido más de una frase distinta.'
        );
    }

    /** Se pidieron unas diez para que la repetición no se note. */
    public function test_hay_al_menos_diez_frases_cargadas(): void
    {
        $this->assertGreaterThanOrEqual(
            10,
            BotRegla::where('Tipo', AsistenteService::TIPO_FRASE)->where('Activo', 1)->count(),
            'Las frases se cargan por migración; si bajan de diez, la misma frase se repite seguido.'
        );
    }
}
