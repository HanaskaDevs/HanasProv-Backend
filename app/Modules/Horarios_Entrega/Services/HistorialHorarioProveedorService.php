<?php

namespace App\Modules\Horarios_Entrega\Services;

use App\Models\Empresa;
use App\Modules\Horarios_Entrega\Models\HistorialHorarioProveedor;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Models\SolicitudAprobacionArribo;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Llena Historial_Horario_Proveedor: una fila por CITA (horario + día),
 * haya llegado el proveedor o no (16-nov-2026, pedido del usuario; diseño
 * en la bitácora 2026-11-16-propuesta-tabla-historial-horario-proveedor.md).
 *
 * QUÉ CITAS ENTRAN: las que tenían al menos un pedido de BC con entrega
 * esperada ese día (BC_Cab_Pedido_Compra.Fecha_Recepcion_Esperada), y las
 * que tuvieron llegada (marca del Guardia o solicitud de arribo tardío)
 * aunque no tuvieran pedido. Las citas sin pedido y sin llegada NO entran
 * (decisión del usuario: "los que digan sin pedido no deben salir").
 *
 * LOS TIEMPOS SON LOS DEL PORTAL (decisión del usuario, "el mismo tiempo"),
 * los mismos de HorarioEntregaService::calcularEstado:
 *  - A tiempo: llegó a la hora citada o antes (0 min de gracia; un segundo
 *    después ya es Atrasado).
 *  - Atrasado: menos de config('portal.minutos_atrasado_a_rechazado') min
 *    tarde (30). Se lee del mismo config: si cambia, cambia en los dos.
 *  - Fuera de plazo: 30 min o más (en el portal el horario pasa a
 *    Rechazado y el Guardia solo puede pedir aprobación).
 *
 * MINUTOS: minutos COMPLETOS (se trunca: 10 min 50 s = 10). Min_Espera =
 * llegada -> primer producto escaneado; Min_Descarga = primer producto ->
 * cierre de la recepción. Las dos salen de columnas que ya llena el job de
 * cada minuto (Hora_Arribo_Real, Hora_Recepcion_Real, Hora_Entregado_Real):
 * no se consulta SIGH acá. Min_Espera negativa = el Guardia marcó la llegada
 * cuando ya estaban recibiendo.
 *
 * TRES MODOS:
 *  - MODO_HOY: el día en curso, completo. Agrega las citas nuevas, actualiza
 *    todo (también la foto del calendario) y quita las que dejaron de
 *    cumplir la regla (pedido anulado y sin llegada).
 *  - MODO_REVISAR: días pasados. Solo actualiza lo que se mueve después
 *    (llegada, aprobación, recepción, estados) y agrega las citas que
 *    tuvieron llegada y no estaban. La foto (código, nombres, horas
 *    citadas, andén) no se toca y nunca se borra nada: es historia.
 *  - MODO_RECONSTRUIR: arma un día pasado entero con el calendario de ese
 *    día (aproximado por Fecha_Creacion / Fecha_Modificacion). Las filas
 *    nuevas quedan con Origen = 'Reconstruido'. Nunca borra.
 */
class HistorialHorarioProveedorService
{
    public const MODO_HOY = 'hoy';
    public const MODO_REVISAR = 'revisar';
    public const MODO_RECONSTRUIR = 'reconstruir';

    public const ORIGEN_DIARIO = 'Diario';
    public const ORIGEN_RECONSTRUIDO = 'Reconstruido';

    /** Días pasados que el job vuelve a mirar (aprobaciones que se resuelven días después). */
    public const DIAS_REVISION = 7;

    /**
     * Primer día del historial (decisión del usuario, 16-nov-2026): desde el
     * 11-nov el Guardia marca las llegadas en serio; antes hay 4 marcas de
     * prueba y el resto saldría "No llegó" sin serlo. Los días anteriores no
     * se procesan.
     */
    public const PRIMER_DIA = '2026-11-11';

    public const A_TIEMPO = 'A tiempo';
    public const ATRASADO = 'Atrasado';
    public const FUERA_DE_PLAZO = 'Fuera de plazo';
    public const NO_LLEGO = 'No llegó';
    public const PENDIENTE = 'Pendiente';

    /** Tope de valores por consulta: SQL Server admite 2100 parámetros. */
    private const TANDA = 1000;

    // Igual que HorarioEntregaService::DIA_POR_INDICE (Carbon: 0 = Domingo).
    private const DIA_POR_INDICE = [
        0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles',
        4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado',
    ];

    /** Lo que se fija al crear la fila y ya no cambia en días pasados. */
    private const CAMPOS_FOTO = [
        'Id_Proveedor', 'Id_Empresa', 'Dia', 'Empresa', 'Cod_Proveedor', 'Ruc', 'Proveedor',
        'Nombre_Comercial', 'Clasificacion', 'Anden', 'Hora_Programada', 'Salida_Programada',
        'Fuera_Planificacion',
    ];

    /** Lo que se sigue moviendo después del día (llegadas, aprobaciones, recepción). */
    private const CAMPOS_MOVIMIENTO = [
        'Hora_Arribo', 'Min_Atraso', 'Llego', 'Puntualidad', 'Estado', 'Aprobacion',
        'Hora_Recepcion', 'Hora_Recibido', 'Min_Espera', 'Min_Descarga',
    ];

    /**
     * Procesa un día.
     *
     * @return array{fecha: string, agregadas: int, actualizadas: int, quitadas: int, sin_cambio: int, filas: array<int, array<string, mixed>>}
     */
    public function procesarDia(CarbonInterface $dia, string $modo, bool $simular = false): array
    {
        $dia = Carbon::parse($dia->toDateString())->startOfDay();
        $ahora = now();
        $fecha = $dia->toDateString();

        if ($modo === self::MODO_HOY && ! $dia->isSameDay($ahora)) {
            throw new \InvalidArgumentException("El modo 'hoy' solo procesa el día en curso ({$ahora->toDateString()}), no el {$fecha}.");
        }

        if ($modo !== self::MODO_HOY && $dia->greaterThanOrEqualTo($ahora->copy()->startOfDay())) {
            throw new \InvalidArgumentException("El modo '{$modo}' es para días pasados; el {$fecha} todavía no terminó.");
        }

        if ($fecha < self::PRIMER_DIA) {
            return ['fecha' => $fecha, 'agregadas' => 0, 'actualizadas' => 0, 'quitadas' => 0, 'sin_cambio' => 0, 'filas' => []];
        }

        $estados = HorarioEntregaEstadoDiario::where('Fecha', $fecha)->get()->keyBy('Id_Horario_Entrega_Proveedor');
        $solicitudes = SolicitudAprobacionArribo::where('Fecha', $fecha)
            ->orderBy('Fecha_Solicitud')
            ->get()
            ->keyBy('Id_Horario_Entrega_Proveedor'); // si hubiera dos, queda la última
        $existentes = HistorialHorarioProveedor::where('Fecha', $fecha)->get()->keyBy('Id_Horario');

        $horarios = $this->horariosDelDia($dia, $modo, $estados->keys()->merge($solicitudes->keys())->merge($existentes->keys()));
        $bc = $this->datosBc($horarios, $fecha);

        $resultado = ['fecha' => $fecha, 'agregadas' => 0, 'actualizadas' => 0, 'quitadas' => 0, 'sin_cambio' => 0, 'filas' => []];
        $cambios = ['insertar' => [], 'actualizar' => [], 'quitar' => []];

        foreach ($horarios as $horario) {
            $id = (int) $horario->Id_Horario_Entrega_Proveedor;
            $existente = $existentes->get($id);
            $ruc = trim((string) $horario->proveedor?->Ruc);
            $infoBc = $bc[(int) $horario->Id_Empresa][$ruc] ?? ['codigo' => null, 'con_pedido' => false];

            $fila = $this->armarFila($horario, $dia, $ahora, $estados->get($id), $solicitudes->get($id), $infoBc['codigo']);

            $entra = $fila['Llego'] === 1 || $infoBc['con_pedido'] || ($existente && $modo !== self::MODO_HOY);

            if (! $entra) {
                if ($existente) {
                    $cambios['quitar'][] = $existente->Id;
                    $resultado['quitadas']++;
                }

                continue;
            }

            $resultado['filas'][] = $fila;

            if (! $existente) {
                $cambios['insertar'][] = $fila + [
                    'Id_Horario' => $id,
                    'Fecha' => $fecha,
                    'Origen' => $modo === self::MODO_RECONSTRUIR ? self::ORIGEN_RECONSTRUIDO : self::ORIGEN_DIARIO,
                    'Actualizado' => $ahora->format('Y-m-d\TH:i:s'),
                ];
                $resultado['agregadas']++;

                continue;
            }

            // Días pasados: la foto del calendario queda como estaba.
            $campos = $modo === self::MODO_HOY ? [...self::CAMPOS_FOTO, ...self::CAMPOS_MOVIMIENTO] : self::CAMPOS_MOVIMIENTO;
            $diferencias = [];

            foreach ($campos as $campo) {
                if ($this->normalizar($existente->getAttribute($campo)) !== $this->normalizar($fila[$campo])) {
                    $diferencias[$campo] = $fila[$campo];
                }
            }

            if ($diferencias === []) {
                $resultado['sin_cambio']++;

                continue;
            }

            $cambios['actualizar'][$existente->Id] = $diferencias + ['Actualizado' => $ahora->format('Y-m-d\TH:i:s')];
            $resultado['actualizadas']++;
        }

        if (! $simular) {
            $this->escribir($cambios);
        }

        return $resultado;
    }

    /**
     * Las citas que se miran ese día: las del calendario (según el modo)
     * más las que tuvieron movimiento o ya están en el historial.
     *
     * @param  Collection<int, mixed>  $idsConMovimiento
     * @return Collection<int, HorarioEntregaProveedor>
     */
    private function horariosDelDia(Carbon $dia, string $modo, Collection $idsConMovimiento): Collection
    {
        $ids = $idsConMovimiento->map(fn ($id) => (int) $id);

        if ($modo === self::MODO_HOY) {
            // Calendario semanal de este día + pedidos fuera de planificación
            // con fecha de hoy (19-nov-2026).
            $ids = $ids->merge(
                HorarioEntregaProveedor::queTocanEl($dia)->where('Activo', 1)->pluck('Id_Horario_Entrega_Proveedor')
            );
        } elseif ($modo === self::MODO_RECONSTRUIR) {
            // El calendario de ESE día, aproximado: creado antes de que
            // terminara el día, y activo hoy o dado de baja ese día o después
            // (la baja deja Fecha_Modificacion; la sincronización con BC no
            // borra horarios, los desactiva).
            $inicio = $dia->format('Y-m-d\TH:i:s');
            $fin = $dia->copy()->addDay()->format('Y-m-d\TH:i:s');

            $ids = $ids->merge(
                HorarioEntregaProveedor::queTocanEl($dia)
                    ->where(fn ($q) => $q->whereNull('Fecha_Creacion')->orWhere('Fecha_Creacion', '<', $fin))
                    ->where(fn ($q) => $q->where('Activo', 1)->orWhere('Fecha_Modificacion', '>=', $inicio))
                    ->pluck('Id_Horario_Entrega_Proveedor')
            );
        }

        $ids = $ids->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->chunk(self::TANDA)
            ->flatMap(fn (Collection $tanda) => HorarioEntregaProveedor::with(['proveedor', 'empresa'])
                ->whereIn('Id_Horario_Entrega_Proveedor', $tanda->all())
                ->get())
            ->values();
    }

    /**
     * Por empresa y RUC: el código de proveedor en BC y si tenía pedido con
     * entrega esperada ese día.
     *
     * El código se resuelve por RUC (Empresa.Empresa_BC + Proveedor.Ruc =
     * BC_Ficha_Proveedor.Nro_Identificacion), igual que el resto del portal:
     * Proveedor.Nro_Proveedor_BC está vacío. Si un RUC tiene más de una
     * ficha en BC (pasa: PRINTOPAC tiene una "INACTIVO ..." y otra
     * vigente), se elige con regla explícita: la que tenga pedido ese día;
     * si no, la única que no esté bloqueada ni se llame "INACTIVO..."; si
     * aún quedan varias, ninguna (mejor vacío que un código equivocado).
     *
     * @param  Collection<int, HorarioEntregaProveedor>  $horarios
     * @return array<int, array<string, array{codigo: ?string, con_pedido: bool}>>
     */
    private function datosBc(Collection $horarios, string $fecha): array
    {
        $resultado = [];

        foreach ($horarios->groupBy('Id_Empresa') as $idEmpresa => $delaEmpresa) {
            $empresaBc = trim((string) $delaEmpresa->first()->empresa?->Empresa_BC);
            $rucs = $delaEmpresa->map(fn ($h) => trim((string) $h->proveedor?->Ruc))->filter()->unique()->values();

            if ($empresaBc === '' || $rucs->isEmpty()) {
                continue;
            }

            $fichas = $rucs->chunk(self::TANDA)->flatMap(fn (Collection $tanda) => DB::table('BC_Ficha_Proveedor')
                ->where('Empresa', $empresaBc)
                ->whereIn('Nro_Identificacion', $tanda->all())
                ->get(['Nro_Proveedor', 'Nro_Identificacion', 'Nombre', 'Bloqueado']));

            $conPedido = $fichas->pluck('Nro_Proveedor')->map(fn ($n) => trim((string) $n))->unique()->chunk(self::TANDA)
                ->flatMap(fn (Collection $tanda) => DB::table('BC_Cab_Pedido_Compra')
                    ->where('Empresa', $empresaBc)
                    ->whereIn('Nro_Proveedor', $tanda->values()->all())
                    ->whereRaw('CONVERT(date, Fecha_Recepcion_Esperada) = CONVERT(date, ?)', [$fecha])
                    ->distinct()
                    ->pluck('Nro_Proveedor'))
                ->map(fn ($n) => trim((string) $n))
                ->flip();

            foreach ($fichas->groupBy(fn ($f) => trim((string) $f->Nro_Identificacion)) as $ruc => $delRuc) {
                $conPedidoDelRuc = $delRuc->filter(fn ($f) => $conPedido->has(trim((string) $f->Nro_Proveedor)));
                $vigentes = $delRuc->filter(fn ($f) => trim((string) $f->Bloqueado) === ''
                    && ! str_starts_with(mb_strtoupper(trim((string) $f->Nombre)), 'INACTIVO'));

                $elegida = match (true) {
                    $delRuc->count() === 1 => $delRuc->first(),
                    $conPedidoDelRuc->count() === 1 => $conPedidoDelRuc->first(),
                    $vigentes->count() === 1 => $vigentes->first(),
                    default => null,
                };

                $resultado[(int) $idEmpresa][(string) $ruc] = [
                    'codigo' => $elegida ? trim((string) $elegida->Nro_Proveedor) : null,
                    'con_pedido' => $conPedidoDelRuc->isNotEmpty(),
                ];
            }
        }

        return $resultado;
    }

    /**
     * Una fila del historial (sin Id_Horario/Fecha/Origen/Actualizado).
     *
     * @return array<string, mixed>
     */
    private function armarFila(
        HorarioEntregaProveedor $horario,
        Carbon $dia,
        CarbonInterface $ahora,
        ?HorarioEntregaEstadoDiario $estado,
        ?SolicitudAprobacionArribo $solicitud,
        ?string $codigoBc,
    ): array {
        $proveedor = $horario->proveedor;
        $cerrado = $dia->lessThan($ahora->copy()->startOfDay());

        $programada = $this->horaDelDia($dia, $horario->Hora_Llegada);
        $salida = $horario->Hora_Salida ? $this->horaDelDia($dia, $horario->Hora_Salida) : null;

        // La llegada es la marca del Guardia; si llegó fuera de plazo y la
        // solicitud no se aprobó (todavía o nunca), la hora de la solicitud:
        // el camión estaba ahí (la aprobación, cuando sale, pone esa misma
        // hora como arribo).
        $arribo = $estado?->Hora_Arribo_Real ?? $solicitud?->Fecha_Solicitud;
        $recepcion = $estado?->Hora_Recepcion_Real;
        $recibido = $estado?->Hora_Entregado_Real;

        $segundosAtraso = $arribo ? (int) $programada->diffInSeconds($arribo, false) : null;

        if ($arribo) {
            // La tolerancia es la de la cita: 30 min el calendario, 10 un
            // pedido fuera de planificación (HorarioEntregaProveedor::excedeTolerancia).
            $puntualidad = match (true) {
                $segundosAtraso <= 0 => self::A_TIEMPO,
                ! $horario->excedeTolerancia($segundosAtraso) => self::ATRASADO,
                default => self::FUERA_DE_PLAZO,
            };
            $minAtraso = intdiv($segundosAtraso, 60);
        } else {
            $puntualidad = $cerrado ? self::NO_LLEGO : self::PENDIENTE;
            // Día en curso: los minutos que lleva de atraso. Día cerrado: vacío.
            $segundosHastaAhora = (int) $programada->diffInSeconds($ahora, false);
            $minAtraso = (! $cerrado && $segundosHastaAhora > 0) ? intdiv($segundosHastaAhora, 60) : null;
        }

        return [
            'Id_Proveedor' => (int) $horario->Id_Proveedor,
            'Id_Empresa' => (int) $horario->Id_Empresa,
            'Dia' => self::DIA_POR_INDICE[$dia->dayOfWeek],
            'Empresa' => $this->texto($horario->empresa?->Empresa_BC),
            'Cod_Proveedor' => $codigoBc ?: $this->texto($proveedor?->Nro_Proveedor_BC),
            'Ruc' => $this->texto($proveedor?->Ruc),
            'Proveedor' => $this->texto($proveedor?->Razon_Social),
            'Nombre_Comercial' => $this->texto($proveedor?->Nombre_Comercial),
            'Clasificacion' => $horario->Clasificacion,
            'Anden' => $this->texto($horario->Anden_Puerta),
            'Hora_Programada' => $programada->format('H:i:s'),
            'Salida_Programada' => $salida?->format('H:i:s'),
            'Hora_Arribo' => $arribo?->format('H:i:s'),
            'Min_Atraso' => $minAtraso,
            'Llego' => $arribo ? 1 : 0,
            'Puntualidad' => $puntualidad,
            'Estado' => $this->estado($horario, $estado, $solicitud, $cerrado, $programada, $ahora),
            'Aprobacion' => $solicitud?->Estado,
            'Hora_Recepcion' => $recepcion?->format('H:i:s'),
            'Hora_Recibido' => $recibido?->format('H:i:s'),
            'Min_Espera' => ($arribo && $recepcion) ? intdiv((int) $arribo->diffInSeconds($recepcion, false), 60) : null,
            'Min_Descarga' => ($recepcion && $recibido) ? intdiv((int) $recepcion->diffInSeconds($recibido, false), 60) : null,
            'Fuera_Planificacion' => $horario->esFueraDePlanificacion() ? 1 : 0,
        ];
    }

    /**
     * Hasta dónde avanzó la cita, con los nombres del portal
     * (HorarioEntregaService::calcularEstado) más los del cierre del día.
     */
    private function estado(
        HorarioEntregaProveedor $horario,
        ?HorarioEntregaEstadoDiario $estado,
        ?SolicitudAprobacionArribo $solicitud,
        bool $cerrado,
        Carbon $programada,
        CarbonInterface $ahora,
    ): string {
        return match (true) {
            (bool) $estado?->Hora_Entregado_Real => 'Recibido',
            (bool) $estado?->Hora_Recepcion_Real => 'En recepción',
            (bool) $estado?->Hora_Arribo_Real => 'Arribo',
            $solicitud?->Estado === 'Pendiente' => 'Por aprobar',
            $solicitud?->Estado === 'Rechazada' => 'Rechazado',
            $cerrado => self::NO_LLEGO,
            $ahora->lessThanOrEqualTo($programada) => 'Programado',
            ! $horario->excedeTolerancia($programada->diffInSeconds($ahora, false)) => 'Atrasado',
            default => 'Rechazado',
        };
    }

    /** 'HH:MM' del calendario puesto sobre la fecha de la cita. */
    private function horaDelDia(Carbon $dia, string $hora): Carbon
    {
        [$h, $m] = array_pad(explode(':', trim($hora)), 2, '0');

        return $dia->copy()->setTime((int) $h, (int) $m);
    }

    private function texto(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /** Para comparar lo guardado con lo calculado: el driver devuelve todo como texto. */
    private function normalizar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        // time(0) puede volver como '05:10:00' o '05:10:00.0000000'.
        if (preg_match('/^(\d{2}:\d{2}:\d{2})\.\d+$/', $texto, $m)) {
            return $m[1];
        }

        return $texto;
    }

    /** @param  array{insertar: array<int, array<string, mixed>>, actualizar: array<int, array<string, mixed>>, quitar: array<int, int>}  $cambios */
    private function escribir(array $cambios): void
    {
        DB::transaction(function () use ($cambios) {
            foreach (array_chunk($cambios['quitar'], self::TANDA) as $tanda) {
                DB::table('Historial_Horario_Proveedor')->whereIn('Id', $tanda)->delete();
            }

            foreach ($cambios['insertar'] as $fila) {
                DB::table('Historial_Horario_Proveedor')->insert($fila);
            }

            foreach ($cambios['actualizar'] as $id => $valores) {
                DB::table('Historial_Horario_Proveedor')->where('Id', $id)->update($valores);
            }
        });
    }
}
