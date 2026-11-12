<?php

namespace App\Console\Commands;

use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Services\SincronizacionSighService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula las horas de recepción y recibido YA GUARDADAS con la misma
 * regla que usa el job de cada minuto (12-nov-2026, decisión del usuario).
 *
 * POR QUÉ HACE FALTA: el sincronizador tuvo tres versiones el mismo día.
 * Primero guardaba la hora en que corría (todo el 12-nov quedó a las 11:38
 * porque SIGH estuvo caído), después el cierre de la recepción en SIGH como
 * "recepción" (Recepción y Recibido salían iguales). La versión final (ver
 * SincronizacionSighService::recepcionesEnWms y ::resumirEntrega) toma:
 *
 *  - Recepción = primer producto escaneado en WMS ese día, de cualquiera de
 *    los pedidos del proveedor;
 *  - Recibido  = cierre del último de esos pedidos (ingreso en
 *    SIGH.DocumentoInv), solo si todos están cerrados.
 *
 * QUÉ HACE, fila por fila (solo las que no tienen marca manual en
 * Marcado_Entregado_Por):
 *
 *  - Si ese día hubo recepción de pedidos del proveedor: deja las horas y
 *    el documento que corresponden ("corregir hora", o "completar" si la
 *    entrega tenía arribo y todavía no tenía nada).
 *  - Si no la hubo y la fila tenía marcas: eran falsas, se borran ("quitar
 *    marca falsa").
 *
 * SEGURIDAD:
 *  - Por defecto SOLO MUESTRA lo que cambiaría. Escribe recién con --aplicar.
 *  - Antes de escribir guarda un respaldo JSON con los valores anteriores
 *    de cada fila que cambia, y se puede volver atrás con --restaurar.
 *  - Si SIGH/WMS falla, aborta sin tocar nada (consulta en modo estricto:
 *    un "vacío" por error se leería como "no hubo recepción" y borraría
 *    horas buenas).
 */
class CorregirHorasSighCommand extends Command
{
    protected $signature = 'horarios-entrega:corregir-horas-sigh
        {--desde=2026-10-22 : Primer día a revisar (la tabla empieza el 22-oct-2026)}
        {--aplicar : Escribe los cambios. Sin esto solo muestra qué cambiaría}
        {--restaurar= : Ruta de un respaldo JSON para volver a los valores anteriores}';

    protected $description = 'Recalcula con SIGH/WMS las horas de recepción y recibido ya guardadas (simula por defecto).';

    public function handle(SincronizacionSighService $sigh): int
    {
        if ($ruta = $this->option('restaurar')) {
            return $this->restaurar($ruta);
        }

        $estados = HorarioEntregaEstadoDiario::whereDate('Fecha', '>=', $this->option('desde'))
            ->whereNull('Marcado_Entregado_Por')
            ->where(function ($q) {
                $q->whereNotNull('Hora_Arribo_Real')
                    ->orWhereNotNull('Hora_Recepcion_Real')
                    ->orWhereNotNull('Hora_Entregado_Real')
                    ->orWhereNotNull('Nro_Documento_Bc');
            })
            ->with('horario.proveedor')
            ->orderBy('Fecha')
            ->get();

        if ($estados->isEmpty()) {
            $this->info('No hay entregas para revisar desde ' . $this->option('desde') . '.');

            return self::SUCCESS;
        }

        try {
            $cambios = $estados
                ->groupBy(fn (HorarioEntregaEstadoDiario $e) => $e->Fecha->toDateString())
                ->flatMap(fn (Collection $delDia) => $this->calcularDia($sigh, $delDia))
                ->filter(fn (array $c) => $c['accion'] !== 'sin cambio')
                ->values();
        } catch (\Throwable $e) {
            $this->error('ABORTA: SIGH/WMS no respondió (' . $e->getMessage() . '). No se tocó nada.');

            return self::FAILURE;
        }

        $this->mostrar($estados->count(), $cambios);

        if ($cambios->isEmpty()) {
            return self::SUCCESS;
        }

        if (! $this->option('aplicar')) {
            $this->warn('SIMULACIÓN: no se escribió nada. Para aplicar, repetir con --aplicar.');

            return self::SUCCESS;
        }

        $respaldo = $this->respaldar($cambios);

        DB::transaction(function () use ($cambios) {
            foreach ($cambios as $c) {
                HorarioEntregaEstadoDiario::whereKey($c['id'])->update([
                    'Hora_Recepcion_Real' => $this->paraSqlServer($c['despues']['recepcion']),
                    'Hora_Entregado_Real' => $this->paraSqlServer($c['despues']['entregado']),
                    'Nro_Documento_Bc' => $c['despues']['documento'],
                    'Fecha_Modificacion' => now()->format('Y-m-d\TH:i:s'),
                ]);
            }
        });

        $this->info("Aplicado: {$cambios->count()} fila(s) corregida(s).");
        $this->info("Respaldo para volver atrás: {$respaldo}");
        $this->line("  php artisan horarios-entrega:corregir-horas-sigh --restaurar={$respaldo}");

        return self::SUCCESS;
    }

    /**
     * Las entregas de UN día: sus pedidos candidatos (con la ventana de ese
     * día) y una sola consulta estricta a SIGH/WMS.
     */
    private function calcularDia(SincronizacionSighService $sigh, Collection $delDia): array
    {
        $fecha = $delDia->first()->Fecha;
        $docsPorFila = [];

        foreach ($delDia as $e) {
            $h = $e->horario;
            $docsPorFila[$e->Id_Horario_Entrega_Estado_Diario] = $h
                ? $sigh->documentosBcDelProveedor($h->Id_Empresa, $h->Id_Proveedor, $fecha)
                : [];
        }

        $todos = array_merge(...array_values($docsPorFila ?: [[]]));
        $recepciones = $todos ? $sigh->recepcionesEnWms($todos, $fecha, lanzarSiFalla: true) : [];

        return $delDia->map(function (HorarioEntregaEstadoDiario $e) use ($sigh, $docsPorFila, $recepciones) {
            $resumen = $sigh->resumirEntrega($docsPorFila[$e->Id_Horario_Entrega_Estado_Diario] ?? [], $recepciones);

            return $this->calcular($e, $resumen);
        })->all();
    }

    /** Qué tendría que quedar en una fila, comparado con lo que tiene. */
    private function calcular(HorarioEntregaEstadoDiario $e, ?array $resumen): array
    {
        $antes = [
            'recepcion' => $e->Hora_Recepcion_Real?->format('Y-m-d H:i:s'),
            'entregado' => $e->Hora_Entregado_Real?->format('Y-m-d H:i:s'),
            'documento' => $e->Nro_Documento_Bc ? trim($e->Nro_Documento_Bc) : null,
        ];
        $teniaMarcas = $antes['recepcion'] || $antes['entregado'] || $antes['documento'];

        if ($resumen && ($e->Hora_Arribo_Real || $teniaMarcas)) {
            $despues = [
                'recepcion' => $resumen['recepcion']->format('Y-m-d H:i:s'),
                'entregado' => $resumen['recibido']?->format('Y-m-d H:i:s'),
                'documento' => $resumen['documento'],
            ];
            $accion = $teniaMarcas ? 'corregir hora' : 'completar';
        } elseif ($teniaMarcas) {
            // Sin recepción de ningún pedido del proveedor ese día: la marca
            // era falsa.
            $despues = ['recepcion' => null, 'entregado' => null, 'documento' => null];
            $accion = 'quitar marca falsa';
        } else {
            $despues = $antes;
            $accion = 'sin cambio';
        }

        // Se compara al minuto: es la precisión con la que se muestra.
        $igual = fn ($a, $b) => substr((string) $a, 0, 16) === substr((string) $b, 0, 16);
        if ($igual($antes['recepcion'], $despues['recepcion'])
            && $igual($antes['entregado'], $despues['entregado'])
            && $antes['documento'] === $despues['documento']) {
            $accion = 'sin cambio';
        }

        return [
            'id' => $e->Id_Horario_Entrega_Estado_Diario,
            'fecha' => $e->Fecha?->toDateString(),
            'proveedor' => $e->horario?->proveedor?->nombreVisible() ?? '?',
            'arribo' => $e->Hora_Arribo_Real?->format('H:i'),
            'antes' => $antes,
            'despues' => $despues,
            'accion' => $accion,
        ];
    }

    private function mostrar(int $revisadas, Collection $cambios): void
    {
        $hora = fn ($v) => $v ? substr($v, 11, 5) : '-';

        $this->table(
            ['Fecha', 'Proveedor', 'Llegó', 'Documento', 'Recepción', 'Recibido', 'Acción'],
            $cambios->map(fn ($c) => [
                $c['fecha'],
                mb_substr($c['proveedor'], 0, 28),
                $c['arribo'] ?? '-',
                ($c['antes']['documento'] ?? '-') . ($c['antes']['documento'] !== $c['despues']['documento'] ? ' -> ' . ($c['despues']['documento'] ?? '-') : ''),
                $hora($c['antes']['recepcion']) . ' -> ' . $hora($c['despues']['recepcion']),
                $hora($c['antes']['entregado']) . ' -> ' . $hora($c['despues']['entregado']),
                $c['accion'],
            ])->all()
        );

        $porAccion = $cambios->countBy('accion');
        $this->info(sprintf(
            'Revisadas: %d · a corregir: %d · a completar: %d · marcas falsas a quitar: %d · sin cambio: %d',
            $revisadas,
            $porAccion['corregir hora'] ?? 0,
            $porAccion['completar'] ?? 0,
            $porAccion['quitar marca falsa'] ?? 0,
            $revisadas - $cambios->count()
        ));
    }

    private function respaldar(Collection $cambios): string
    {
        $carpeta = storage_path('app/respaldos');

        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        $ruta = $carpeta . '/horas-sigh-' . now()->format('Ymd-His') . '.json';

        file_put_contents($ruta, json_encode(
            $cambios->map(fn ($c) => ['id' => $c['id']] + $c['antes'])->values()->all(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ));

        return $ruta;
    }

    private function restaurar(string $ruta): int
    {
        if (! is_file($ruta)) {
            $this->error("No existe el respaldo {$ruta}.");

            return self::FAILURE;
        }

        $filas = json_decode((string) file_get_contents($ruta), true);

        if (! is_array($filas)) {
            $this->error('El respaldo no es un JSON válido.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($filas) {
            foreach ($filas as $f) {
                HorarioEntregaEstadoDiario::whereKey($f['id'])->update([
                    'Hora_Recepcion_Real' => $this->paraSqlServer($f['recepcion']),
                    'Hora_Entregado_Real' => $this->paraSqlServer($f['entregado']),
                    'Nro_Documento_Bc' => $f['documento'],
                    'Fecha_Modificacion' => now()->format('Y-m-d\TH:i:s'),
                ]);
            }
        });

        $this->info('Restauradas ' . count($filas) . " fila(s) desde {$ruta}.");

        return self::SUCCESS;
    }

    /**
     * 'Y-m-d H:i:s' -> 'Y-m-d\TH:i:s'.
     *
     * update() del query builder NO pasa por los casts del modelo, así que
     * acá no aplica el $dateFormat de BaseModel. Y con el idioma de la
     * sesión en español (DATEFORMAT dmy), SQL Server puede leer
     * '2026-11-12 05:45:22' como día-mes: falla o, peor, guarda otra fecha.
     * Con la T en medio lo lee siempre como año-mes-día.
     */
    private function paraSqlServer(?string $fechaHora): ?string
    {
        return $fechaHora ? str_replace(' ', 'T', substr($fechaHora, 0, 19)) : null;
    }
}
