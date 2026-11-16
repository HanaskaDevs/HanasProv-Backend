<?php

namespace App\Console\Commands;

use App\Modules\Horarios_Entrega\Services\HistorialHorarioProveedorService as Historial;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Llena Historial_Horario_Proveedor (una fila por cita, haya llegado el
 * proveedor o no). Ver HistorialHorarioProveedorService.
 *
 * Sin opciones (lo que corre el programador cada 5 minutos):
 *   - hoy, completo;
 *   - los DIAS_REVISION días anteriores, solo lo que se mueve después
 *     (aprobaciones de arribo que se resuelven días después, recepción).
 *
 * --reconstruir --desde=AAAA-MM-DD [--hasta=AAAA-MM-DD]: arma días pasados
 *   con el calendario de esos días (para cargar la historia una vez, desde
 *   el 11-nov-2026). Nunca borra; las filas nuevas quedan "Reconstruido".
 *
 * --simular: no escribe nada, muestra lo que haría.
 *
 * Solo lee tablas del portal (no SIGH): si falla, el próximo turno reintenta.
 */
class HistorialHorarioProveedorCommand extends Command
{
    protected $signature = 'horarios-entrega:historial
        {--reconstruir : Arma días pasados completos (con --desde)}
        {--desde= : Primer día a reconstruir (AAAA-MM-DD)}
        {--hasta= : Último día a reconstruir (AAAA-MM-DD; por defecto ayer)}
        {--simular : No escribe nada, solo muestra lo que haría}';

    protected $description = 'Llena el historial de horario por proveedor (una fila por cita, con su llegada, puntualidad y estado).';

    public function handle(Historial $historial): int
    {
        $simular = (bool) $this->option('simular');

        try {
            $resultados = $this->option('reconstruir')
                ? $this->reconstruir($historial, $simular)
                : $this->corridaNormal($historial, $simular);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (\Throwable $e) {
            Log::error('horarios-entrega:historial falló.', ['error' => $e->getMessage()]);
            $this->error('No se pudo actualizar el historial: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Fecha', 'Agregadas', 'Actualizadas', 'Quitadas', 'Sin cambio', 'Filas'],
            array_map(fn ($r) => [$r['fecha'], $r['agregadas'], $r['actualizadas'], $r['quitadas'], $r['sin_cambio'], count($r['filas'])], $resultados)
        );

        if ($this->output->isVerbose()) {
            foreach ($resultados as $r) {
                $this->line("\n{$r['fecha']}");
                $this->table(
                    ['Cod', 'Proveedor', 'Clasif.', 'Prog.', 'Arribo', 'Atraso', 'Puntualidad', 'Estado', 'Aprob.', 'Recep.', 'Recibido', 'Espera', 'Descarga'],
                    array_map(fn ($f) => [
                        $f['Cod_Proveedor'], mb_substr((string) $f['Proveedor'], 0, 32), $f['Clasificacion'],
                        substr((string) $f['Hora_Programada'], 0, 5), substr((string) $f['Hora_Arribo'], 0, 5), $f['Min_Atraso'],
                        $f['Puntualidad'], $f['Estado'], $f['Aprobacion'],
                        substr((string) $f['Hora_Recepcion'], 0, 5), substr((string) $f['Hora_Recibido'], 0, 5), $f['Min_Espera'], $f['Min_Descarga'],
                    ], $r['filas'])
                );
            }
        }

        if ($simular) {
            $this->warn('Simulación: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function corridaNormal(Historial $historial, bool $simular): array
    {
        $hoy = now()->startOfDay();
        $resultados = [$historial->procesarDia($hoy, Historial::MODO_HOY, $simular)];

        for ($d = 1; $d <= Historial::DIAS_REVISION; $d++) {
            $resultados[] = $historial->procesarDia($hoy->copy()->subDays($d), Historial::MODO_REVISAR, $simular);
        }

        return $resultados;
    }

    /** @return array<int, array<string, mixed>> */
    private function reconstruir(Historial $historial, bool $simular): array
    {
        if (! $this->option('desde')) {
            throw new \InvalidArgumentException('--reconstruir necesita --desde=AAAA-MM-DD.');
        }

        $desde = Carbon::parse($this->option('desde'))->startOfDay();
        $hasta = $this->option('hasta') ? Carbon::parse($this->option('hasta'))->startOfDay() : now()->subDay()->startOfDay();

        if ($desde->greaterThan($hasta)) {
            throw new \InvalidArgumentException('--desde es posterior a --hasta.');
        }

        $resultados = [];

        for ($dia = $desde->copy(); $dia->lessThanOrEqualTo($hasta); $dia->addDay()) {
            $resultados[] = $historial->procesarDia($dia->copy(), Historial::MODO_RECONSTRUIR, $simular);
        }

        return $resultados;
    }
}
