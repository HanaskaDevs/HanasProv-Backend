<?php

namespace App\Console\Commands;

use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Services\SincronizacionSighService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Corrige las horas de recepción y entrega YA GUARDADAS con la hora real de
 * SIGH (12-nov-2026, decisión del usuario).
 *
 * POR QUÉ HACE FALTA: hasta el 12-nov el sincronizador guardaba la hora en
 * que corría (now()) y además aceptaba como "ingreso" movimientos viejos de
 * otros pedidos que comparten número de ítem (ver
 * SincronizacionSighService::documentosConIngresoEnDocumentoInv). El
 * resultado en el historial: horas que no son las de la recepción (todas
 * las del 12-nov a las 11:38) y entregas marcadas "en recepción" o
 * "recibidas" que en SIGH no se habían recibido.
 *
 * QUÉ HACE, fila por fila (solo las que llenó el job: con Nro_Documento_Bc
 * y sin marca manual en Marcado_Entregado_Por):
 *
 *  - Si en SIGH hay un ingreso real de ese pedido EL MISMO DÍA de la
 *    entrega: pone esa hora en la recepción, y también en la entrega si el
 *    pedido ya está cerrado (EstadoPedido = 'N'; en SIGH se cierra en el
 *    mismo acto del ingreso).
 *  - Si no lo hay: la marca era falsa, así que deja la fila como estaba
 *    antes de que el job la tocara (sin recepción, sin entrega, sin
 *    documento). Si es de hoy, el job la vuelve a evaluar con la regla
 *    nueva.
 *
 * SEGURIDAD:
 *  - Por defecto SOLO MUESTRA lo que cambiaría. Escribe recién con --aplicar.
 *  - Antes de escribir guarda un respaldo JSON con los valores anteriores
 *    de cada fila que cambia, y se puede volver atrás con --restaurar.
 *  - Si SIGH falla, aborta sin tocar nada (consultas en modo estricto: un
 *    "vacío" por error se leería como "no hubo ingreso" y borraría horas).
 */
class CorregirHorasSighCommand extends Command
{
    protected $signature = 'horarios-entrega:corregir-horas-sigh
        {--desde=2026-10-22 : Primer día a revisar (la tabla empieza el 22-oct-2026)}
        {--aplicar : Escribe los cambios. Sin esto solo muestra qué cambiaría}
        {--restaurar= : Ruta de un respaldo JSON para volver a los valores anteriores}';

    protected $description = 'Corrige con la hora real de SIGH las horas de recepción/entrega ya guardadas (simula por defecto).';

    /** Mismo tope que el resto del proyecto: SQL Server admite 2100 parámetros. */
    private const TANDA = 1000;

    public function handle(SincronizacionSighService $sigh): int
    {
        if ($ruta = $this->option('restaurar')) {
            return $this->restaurar($ruta);
        }

        $estados = HorarioEntregaEstadoDiario::whereDate('Fecha', '>=', $this->option('desde'))
            ->whereNotNull('Nro_Documento_Bc')
            ->whereNull('Marcado_Entregado_Por')
            ->with('horario.proveedor')
            ->orderBy('Fecha')
            ->get();

        if ($estados->isEmpty()) {
            $this->info('No hay filas llenadas por el sincronizador desde ' . $this->option('desde') . '.');

            return self::SUCCESS;
        }

        $documentos = $estados->pluck('Nro_Documento_Bc')->unique()->values()->all();

        try {
            [$ingresos, $recibidos] = $this->consultarSigh($sigh, $documentos);
        } catch (\Throwable $e) {
            $this->error('ABORTA: SIGH no respondió (' . $e->getMessage() . '). No se tocó nada.');

            return self::FAILURE;
        }

        $cambios = $estados
            ->map(fn (HorarioEntregaEstadoDiario $e) => $this->calcular($e, $ingresos, $recibidos))
            ->filter(fn (array $c) => $c['accion'] !== 'sin cambio')
            ->values();

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
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    private function consultarSigh(SincronizacionSighService $sigh, array $documentos): array
    {
        $ingresos = [];
        $recibidos = [];

        foreach (array_chunk($documentos, self::TANDA) as $tanda) {
            $ingresos += $sigh->documentosConIngresoEnDocumentoInv($tanda, true);
            $recibidos += $sigh->documentosRecibidos($tanda, true);
        }

        return [$ingresos, $recibidos];
    }

    /** Qué tendría que quedar en una fila, comparado con lo que tiene. */
    private function calcular(HorarioEntregaEstadoDiario $e, array $ingresos, array $recibidos): array
    {
        $doc = $e->Nro_Documento_Bc;
        $dia = $e->Fecha?->toDateString();
        $ingreso = $ingresos[$doc] ?? null;

        if ($ingreso && $dia && substr($ingreso, 0, 10) === $dia) {
            $despues = [
                'recepcion' => $ingreso,
                'entregado' => isset($recibidos[$doc]) ? $ingreso : null,
                'documento' => $doc,
            ];
        } else {
            // Sin ingreso real ese día: la marca era falsa.
            $despues = ['recepcion' => null, 'entregado' => null, 'documento' => null];
        }

        $antes = [
            'recepcion' => $e->Hora_Recepcion_Real?->format('Y-m-d H:i:s'),
            'entregado' => $e->Hora_Entregado_Real?->format('Y-m-d H:i:s'),
            'documento' => $doc,
        ];

        // Se compara al minuto: es la precisión con la que se muestra.
        $igual = fn ($a, $b) => substr((string) $a, 0, 16) === substr((string) $b, 0, 16);
        $sinCambio = $igual($antes['recepcion'], $despues['recepcion'])
            && $igual($antes['entregado'], $despues['entregado'])
            && $antes['documento'] === $despues['documento'];

        return [
            'id' => $e->Id_Horario_Entrega_Estado_Diario,
            'fecha' => $dia,
            'proveedor' => $e->horario?->proveedor?->nombreVisible() ?? '?',
            'antes' => $antes,
            'despues' => $despues,
            'accion' => $sinCambio ? 'sin cambio' : ($despues['recepcion'] ? 'corregir hora' : 'quitar marca falsa'),
        ];
    }

    private function mostrar(int $revisadas, Collection $cambios): void
    {
        $hora = fn ($v) => $v ? substr($v, 11, 5) : '-';

        $this->table(
            ['Fecha', 'Proveedor', 'Documento', 'Recepción', 'Entrega', 'Acción'],
            $cambios->map(fn ($c) => [
                $c['fecha'],
                mb_substr($c['proveedor'], 0, 28),
                $c['antes']['documento'],
                $hora($c['antes']['recepcion']) . ' -> ' . $hora($c['despues']['recepcion']),
                $hora($c['antes']['entregado']) . ' -> ' . $hora($c['despues']['entregado']),
                $c['accion'],
            ])->all()
        );

        $porAccion = $cambios->countBy('accion');
        $this->info(sprintf(
            'Revisadas: %d · a corregir la hora: %d · marcas falsas a quitar: %d · sin cambio: %d',
            $revisadas,
            $porAccion['corregir hora'] ?? 0,
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
