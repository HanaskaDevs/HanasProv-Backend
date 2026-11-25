<?php

namespace App\Modules\Horarios_Entrega\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Models\HistorialHorarioProveedor;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Proveedores\Models\Proveedor;
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
 *  - `Hora_Recepcion_Real` = primer producto escaneado en WMS ese día
 *    (inicio de la recepción) y `Hora_Entregado_Real` = cierre de la
 *    recepción en SIGH (del último pedido, si trajo varios). Así desde el
 *    12-nov; antes el job guardaba la hora en que corría y después el
 *    cierre en las dos columnas (todas a las 11:38 el 12-nov, o recepción
 *    = recibido). El histórico se recalculó con
 *    horarios-entrega:corregir-horas-sigh. Detalle del proceso en
 *    SincronizacionSighService::recepcionesEnWms.
 *
 * LOS RECHAZADOS (25-nov-2026, pedido del usuario: "que también salgan los
 * rechazados que no llegaron y los rechazados que llegaron atrasados"):
 * Horario_Entrega_Estado_Diario solo tiene fila cuando el Guardia marca la
 * llegada (o se aprueba un arribo tardío), así que un rechazado nunca
 * aparecía. Esas citas salen de Historial_Horario_Proveedor (una fila por
 * cita con pedido o con llegada, desde el 11-nov-2026; la escribe
 * HistorialHorarioProveedorService cada 5 min):
 *  - Rechazado_No_Llego: tenía pedido y pasó la tolerancia sin que se
 *    marcara la llegada ("No llegó" en días cerrados; "Rechazado" sin
 *    solicitud, el día en curso).
 *  - Rechazado_Atrasado: llegó fuera de plazo y el Guardia pidió aprobación,
 *    pendiente ("Por aprobar") o rechazada por Calidad ("Rechazado" con
 *    Aprobacion = Rechazada). Su llegada es la hora de la solicitud: cuenta
 *    en el atraso, porque sí llegó.
 * Las que se aprobaron ya tienen fila en Estado_Diario y salen como siempre.
 */
class HistorialEntregasService
{
    /** Tope de historia que se puede pedir (pedido del usuario: 3 meses). */
    public const MAX_DIAS = 92;

    public const RECHAZADO_NO_LLEGO = 'Rechazado_No_Llego';

    public const RECHAZADO_ATRASADO = 'Rechazado_Atrasado';

    /** Lo que se muestra al entrar, sin tocar los filtros. */
    public const DIAS_POR_DEFECTO = 30;

    /**
     * Sistemas, Admin y Compras (pedido del usuario, 12-nov-2026). Calidad
     * lo tuvo unas horas ese mismo día y salió a propósito: el reporte mide
     * la puntualidad de las entregas, que es seguimiento de Compras.
     * Bodega desde el 25-nov-2026 (pedido del usuario): recibe esas entregas.
     */
    public function verificarAcceso(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->Tipo_Usuario === 'Interno'
            && ($usuario->esSistemas($idEmpresa) || $usuario->esAdmin($idEmpresa) || $usuario->esCompras($idEmpresa)
                || $usuario->esBodega($idEmpresa));

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

        $estados = $consulta->get()->filter(fn (HorarioEntregaEstadoDiario $e) => $e->horario !== null);

        // Las citas que ya salen por su fila de llegada no se repiten como rechazadas.
        $yaIncluidas = $estados
            ->map(fn (HorarioEntregaEstadoDiario $e) => (int) $e->Id_Horario_Entrega_Proveedor.'|'.$e->Fecha?->toDateString())
            ->flip();

        // toBase(): sin filas de llegada, el map de una colección de Eloquent
        // vacía sigue siendo de Eloquent, y su merge pide getKey() a arreglos.
        $entregas = $estados->toBase()
            ->map(fn (HorarioEntregaEstadoDiario $e) => $this->serializar($e))
            ->merge($this->rechazados($idEmpresa, $desde, $hasta, $filtros, $yaIncluidas))
            // Lo más reciente primero, como antes; sortBy es estable, así que
            // dentro del mismo día se conserva el orden de llegada.
            ->sortByDesc('fecha')
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
            // Clave única de la fila en la pantalla: las rechazadas no tienen
            // Id_Horario_Entrega_Estado_Diario (ver rechazados()).
            'clave' => 'e'.$estado->Id_Horario_Entrega_Estado_Diario,
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
            // Salida PROGRAMADA del calendario (la misma que muestra el
            // Modo TV). El portal no registra la hora real en que se va el
            // camión: nadie la marca.
            'hora_salida' => $horario->Hora_Salida ? substr((string) $horario->Hora_Salida, 0, 5) : null,
            // Recepción = primer producto escaneado en WMS; recibido =
            // cierre de la recepción en SIGH (ver la cabecera). Si lo marcó
            // Compras a mano, recibido es la hora de esa marca.
            'hora_recepcion' => $estado->Hora_Recepcion_Real?->format('H:i'),
            'hora_entregado' => $estado->Hora_Entregado_Real?->format('H:i'),
            // Cuánto esperó desde que llegó hasta que empezaron a recibirlo
            // (primer escaneo). Negativo = el Guardia marcó el arribo tarde.
            'minutos_espera' => ($arribo && $estado->Hora_Recepcion_Real)
                ? (int) round($arribo->diffInSeconds($estado->Hora_Recepcion_Real, false) / 60)
                : null,
            'nro_documento_bc' => $estado->Nro_Documento_Bc,
            'estado_final' => $this->estadoFinal($estado),
            'aprobacion' => null,
        ];
    }

    /**
     * Las citas rechazadas del rango (ver la cabecera), con la misma forma
     * que una entrega. Sin recepción ni recibido: no se recibieron.
     *
     * @param  Collection<string, int>  $yaIncluidas  'Id_Horario|Y-m-d' de las que ya salen
     */
    protected function rechazados(int $idEmpresa, CarbonInterface $desde, CarbonInterface $hasta, array $filtros, Collection $yaIncluidas): Collection
    {
        return HistorialHorarioProveedor::query()
            ->where('Id_Empresa', $idEmpresa)
            ->whereBetween('Fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->whereIn('Estado', [HistorialHorarioProveedorService::NO_LLEGO, 'Por aprobar', 'Rechazado'])
            ->when(! empty($filtros['id_proveedor']), fn ($q) => $q->where('Id_Proveedor', $filtros['id_proveedor']))
            ->when(! empty($filtros['clasificacion']), fn ($q) => $q->where('Clasificacion', $filtros['clasificacion']))
            ->orderByDesc('Fecha')
            ->orderBy('Hora_Programada')
            ->get()
            ->reject(fn (HistorialHorarioProveedor $h) => $yaIncluidas->has((int) $h->Id_Horario.'|'.substr((string) $h->Fecha, 0, 10)))
            ->map(fn (HistorialHorarioProveedor $h) => $this->serializarRechazado($h))
            ->values();
    }

    protected function serializarRechazado(HistorialHorarioProveedor $h): array
    {
        // Llegó atrasado = hay solicitud de aprobación (pendiente o rechazada).
        // Sin solicitud, no llegó: el día en curso "Rechazado" sin aprobación
        // es eso mismo, todavía sin cerrar.
        $llegoAtrasado = $h->Estado === 'Por aprobar' || $h->Aprobacion === 'Rechazada';

        return [
            'clave' => 'h'.$h->Id,
            'id_horario_entrega_estado_diario' => null,
            'fecha' => substr((string) $h->Fecha, 0, 10),
            'id_proveedor' => $h->Id_Proveedor !== null ? (int) $h->Id_Proveedor : null,
            'proveedor' => Proveedor::nombreVisibleDe($h->Proveedor),
            'ruc' => $h->Ruc,
            'clasificacion' => $h->Clasificacion,
            'anden_puerta' => $h->Anden,
            'hora_programada' => $this->horaCorta($h->Hora_Programada),
            'hora_arribo' => $llegoAtrasado ? $this->horaCorta($h->Hora_Arribo) : null,
            // Solo si llegó: el "atraso" de un no-show del día en curso son
            // los minutos que lleva esperando, no un atraso de llegada.
            'minutos_atraso' => $llegoAtrasado && $h->Min_Atraso !== null ? (int) $h->Min_Atraso : null,
            'hora_salida' => $this->horaCorta($h->Salida_Programada),
            'hora_recepcion' => null,
            'hora_entregado' => null,
            'minutos_espera' => null,
            'nro_documento_bc' => null,
            'estado_final' => $llegoAtrasado ? self::RECHAZADO_ATRASADO : self::RECHAZADO_NO_LLEGO,
            // Pendiente / Rechazada: en qué quedó la solicitud de arribo tardío.
            'aprobacion' => $llegoAtrasado ? ($h->Aprobacion ?: 'Pendiente') : null,
        ];
    }

    /** '07:30:00' o '07:30:00.0000000' -> '07:30'. */
    protected function horaCorta(mixed $hora): ?string
    {
        $hora = trim((string) $hora);

        return $hora === '' ? null : substr($hora, 0, 5);
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
                    // Todas sus citas del rango, también las rechazadas (25-nov-2026).
                    'entregas' => $delProveedor->count(),
                    // Solo las filas de llegada sin arribo marcado; los que no
                    // llegaron van aparte en rechazados_no_llego.
                    'sin_arribo_marcado' => $delProveedor->where('estado_final', 'Sin_Arribo')->count(),
                    'rechazados_no_llego' => $delProveedor->where('estado_final', self::RECHAZADO_NO_LLEGO)->count(),
                    'rechazados_atrasado' => $delProveedor->where('estado_final', self::RECHAZADO_ATRASADO)->count(),
                    'a_tiempo' => $atrasos->filter(fn (int $m) => $m <= 0)->count(),
                    'tarde' => $atrasos->filter(fn (int $m) => $m > 0)->count(),
                    'atraso_promedio' => $atrasos->isEmpty() ? null : (int) round($atrasos->avg()),
                    'atraso_maximo' => $atrasos->isEmpty() ? null : (int) $atrasos->max(),
                    'recibidas' => $delProveedor->where('estado_final', 'Recibido')->count(),
                    // Solo esperas >= 0: si el Guardia marcó el arribo después
                    // de que SIGH ya lo había recibido (pasa), la diferencia
                    // sale negativa y no es una espera.
                    'espera_promedio' => ($esperas = $delProveedor->pluck('minutos_espera')->filter(fn ($m) => $m !== null && $m >= 0))->isEmpty()
                        ? null
                        : (int) round($esperas->avg()),
                ];
            })
            ->sortByDesc(fn (array $p) => $p['atraso_promedio'] ?? -9999)
            ->values();
    }
}
