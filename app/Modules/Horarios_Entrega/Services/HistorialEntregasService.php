<?php

namespace App\Modules\Horarios_Entrega\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Reporte "Historial de proveedores" (12-nov-2026, pedido del usuario:
 * "ver sus estados, los horarios de registro, para ver cuánto se demora
 * cada proveedor... hasta 3 meses, con filtro").
 *
 * Una fila por ENTREGA (proveedor + día), con la hora a la que estaba
 * citado, la hora a la que llegó de verdad y los minutos de diferencia.
 * Más un resumen por proveedor: cuántas entregas, cuántas a tiempo y
 * cuánto se atrasa en promedio.
 *
 * DE DÓNDE SALE: `Horario_Entrega_Estado_Diario`, la misma tabla que
 * alimenta el Seguimiento de hoy. Acá no se calcula ningún estado "en
 * vivo": es historia, lo que quedó registrado ese día.
 *
 * QUÉ DATO ES CONFIABLE Y CUÁL NO (importante, 12-nov-2026):
 *
 *  - `Hora_Arribo_Real` la escribe el Guardia al marcar el arribo, así que
 *    es la hora real en que llegó el camión. Sobre ella se calculan la
 *    puntualidad y el atraso, que es lo que se pidió medir.
 *  - `Hora_Recepcion_Real` y `Hora_Entregado_Real` las escribe el
 *    sincronizador de SIGH con `now()` (ver
 *    HorarioEntregaService::marcarRecepcionAutomatica), o sea con la hora
 *    en que corrió el job, NO con la hora del evento en SIGH. Si el job se
 *    atrasa -pasó: estuvo caído del 27-oct al 4-nov-, varias entregas
 *    quedan con el mismo minuto. Por eso el reporte los muestra como
 *    informativos y NO calcula con ellos la nota de nadie.
 */
class HistorialEntregasService
{
    /** Tope de historia que se puede pedir (pedido del usuario: 3 meses). */
    public const MAX_DIAS = 92;

    /** Lo que se muestra al entrar, sin tocar los filtros. */
    public const DIAS_POR_DEFECTO = 30;

    /**
     * Mismos roles que el resto de los reportes de Calidad: Calidad, Admin
     * y Sistemas (ver ReporteCaducidadService::verificarAcceso).
     */
    public function verificarAcceso(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->Tipo_Usuario === 'Interno'
            && ($usuario->esSistemas($idEmpresa) || $usuario->esAdmin($idEmpresa) || $usuario->esCalidad($idEmpresa));

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('No tiene permisos para ver el historial de proveedores.');
        }
    }

    /**
     * El historial con sus filtros.
     *
     * @param  array{desde?: ?string, hasta?: ?string, id_proveedor?: ?int, clasificacion?: ?string}  $filtros
     */
    public function consultar(Usuario $usuario, int $idEmpresa, array $filtros = []): array
    {
        $this->verificarAcceso($usuario, $idEmpresa);

        [$desde, $hasta] = $this->rango($filtros['desde'] ?? null, $filtros['hasta'] ?? null);

        $consulta = HorarioEntregaEstadoDiario::query()
            ->whereBetween('Fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->whereHas('horario', function ($q) use ($idEmpresa, $filtros) {
                $q->where('Id_Empresa', $idEmpresa);

                if (! empty($filtros['id_proveedor'])) {
                    $q->where('Id_Proveedor', $filtros['id_proveedor']);
                }

                if (! empty($filtros['clasificacion'])) {
                    $q->where('Clasificacion', $filtros['clasificacion']);
                }
            })
            ->with(['horario.proveedor', 'horario.empresa'])
            ->orderByDesc('Fecha')
            ->orderBy('Id_Horario_Entrega_Estado_Diario');

        $entregas = $consulta->get()
            ->filter(fn (HorarioEntregaEstadoDiario $e) => $e->horario !== null)
            ->map(fn (HorarioEntregaEstadoDiario $e) => $this->serializar($e))
            ->values();

        return [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'max_dias' => self::MAX_DIAS,
            'entregas' => $entregas,
            'por_proveedor' => $this->resumenPorProveedor($entregas),
        ];
    }

    /**
     * Rango pedido, acotado a MAX_DIAS.
     *
     * El tope se aplica SIEMPRE en el servidor y no solo en la pantalla:
     * el endpoint es público para cualquier usuario interno con permiso, y
     * sin tope alguien podría pedir "desde 2020" y barrer la tabla entera.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    protected function rango(?string $desde, ?string $hasta): array
    {
        $fin = $hasta ? Carbon::parse($hasta)->startOfDay() : Carbon::now()->startOfDay();
        $inicio = $desde ? Carbon::parse($desde)->startOfDay() : $fin->copy()->subDays(self::DIAS_POR_DEFECTO - 1);

        if ($inicio->greaterThan($fin)) {
            [$inicio, $fin] = [$fin->copy(), $inicio->copy()];
        }

        // Más de 3 meses: se recorta el comienzo, no se rechaza la
        // consulta -> es más útil devolver los 3 meses que un error.
        if ($inicio->diffInDays($fin) + 1 > self::MAX_DIAS) {
            $inicio = $fin->copy()->subDays(self::MAX_DIAS - 1);
        }

        return [$inicio, $fin];
    }

    /**
     * Una entrega del historial.
     *
     * `minutos_atraso`: positivo = llegó tarde, negativo = llegó antes,
     * null = no se marcó el arribo (no se puede saber). Se compara contra
     * la hora a la que estaba citado ESE día.
     */
    protected function serializar(HorarioEntregaEstadoDiario $estado): array
    {
        $horario = $estado->horario;
        $proveedor = $horario->proveedor;

        $programada = $this->horaProgramadaDelDia($estado->Fecha, $horario->Hora_Llegada);
        $arribo = $estado->Hora_Arribo_Real;

        return [
            'id_horario_entrega_estado_diario' => $estado->Id_Horario_Entrega_Estado_Diario,
            'fecha' => $estado->Fecha?->toDateString(),
            'id_proveedor' => $proveedor?->Id_Proveedor,
            'proveedor' => $proveedor?->nombreVisible(),
            'ruc' => $proveedor?->Ruc,
            'clasificacion' => $horario->Clasificacion,
            'anden_puerta' => $horario->Anden_Puerta,
            'hora_programada' => $horario->Hora_Llegada ? substr((string) $horario->Hora_Llegada, 0, 5) : null,
            'hora_arribo' => $arribo?->format('H:i'),
            'minutos_atraso' => ($programada && $arribo) ? (int) round($programada->diffInSeconds($arribo, false) / 60) : null,
            // Informativos: los escribe el job de SIGH con su propia hora.
            'hora_recepcion' => $estado->Hora_Recepcion_Real?->format('H:i'),
            'hora_entregado' => $estado->Hora_Entregado_Real?->format('H:i'),
            'nro_documento_bc' => $estado->Nro_Documento_Bc,
            'estado_final' => $this->estadoFinal($estado),
        ];
    }

    /**
     * El último estado al que llegó esa entrega. No se recalcula nada "en
     * vivo" (eso es el Seguimiento de hoy): se mira hasta dónde avanzó.
     */
    protected function estadoFinal(HorarioEntregaEstadoDiario $estado): string
    {
        if ($estado->Hora_Entregado_Real) {
            return 'Recibido';
        }

        if ($estado->Hora_Recepcion_Real) {
            return 'En_Recepcion';
        }

        return $estado->Hora_Arribo_Real ? 'Arribo' : 'Sin_Arribo';
    }

    /** La hora citada, puesta sobre la fecha de esa entrega. */
    protected function horaProgramadaDelDia(?CarbonInterface $fecha, ?string $horaLlegada): ?CarbonInterface
    {
        if (! $fecha || ! $horaLlegada) {
            return null;
        }

        [$h, $m] = array_pad(explode(':', (string) $horaLlegada), 2, '0');

        return Carbon::parse($fecha->toDateString())->setTime((int) $h, (int) $m);
    }

    /**
     * Resumen por proveedor: es lo que contesta "¿quién se demora?".
     *
     * `a_tiempo` cuenta las entregas con 0 o menos minutos de atraso. Las
     * que no tienen arribo marcado no entran en los promedios -no se sabe
     * a qué hora llegó-, pero sí se cuentan aparte para que se vea cuántas
     * veces no quedó registro.
     */
    protected function resumenPorProveedor(Collection $entregas): Collection
    {
        return $entregas
            ->filter(fn (array $e) => $e['id_proveedor'] !== null)
            ->groupBy('id_proveedor')
            ->map(function (Collection $delProveedor) {
                $conArribo = $delProveedor->filter(fn (array $e) => $e['minutos_atraso'] !== null);
                $atrasos = $conArribo->pluck('minutos_atraso');

                return [
                    'id_proveedor' => $delProveedor->first()['id_proveedor'],
                    'proveedor' => $delProveedor->first()['proveedor'],
                    'ruc' => $delProveedor->first()['ruc'],
                    'entregas' => $delProveedor->count(),
                    'sin_arribo_marcado' => $delProveedor->count() - $conArribo->count(),
                    'a_tiempo' => $atrasos->filter(fn (int $m) => $m <= 0)->count(),
                    'tarde' => $atrasos->filter(fn (int $m) => $m > 0)->count(),
                    'atraso_promedio' => $atrasos->isEmpty() ? null : (int) round($atrasos->avg()),
                    'atraso_maximo' => $atrasos->isEmpty() ? null : (int) $atrasos->max(),
                    'recibidas' => $delProveedor->where('estado_final', 'Recibido')->count(),
                ];
            })
            ->sortByDesc(fn (array $p) => $p['atraso_promedio'] ?? -9999)
            ->values();
    }
}
