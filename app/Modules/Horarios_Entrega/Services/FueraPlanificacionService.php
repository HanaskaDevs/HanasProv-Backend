<?php

namespace App\Modules\Horarios_Entrega\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Pedidos fuera de planificación (19-nov-2026, pedido del usuario).
 *
 * Compras agenda en Business Central (extensión Calendario_Portal_
 * Proveedores, página "Pedidos fuera de planificación") turnos sueltos para
 * esta semana, con los mismos campos del calendario: clasificación, día,
 * proveedor, andén y hora de llegada, más la duración (20, 40 o 60 min).
 * BC los manda acá y se guardan en Horario_Entrega_Proveedor con
 * Fecha_Fuera_Planificacion: desde ahí el seguimiento de hoy, el Modo TV, el
 * aviso a Calidad, la recepción en bodega y el historial los tratan como a
 * cualquier cita.
 *
 * REGLAS (config('portal.fuera_planificacion'); las mismas valida BC):
 *  - Solo dentro de las ventanas de cada clasificación y día.
 *  - Duración 20, 40 o 60 min, y el turno termina a la hora de cierre o
 *    antes (cierre 16:00: a las 15:50 no cabe uno de 20).
 *  - Un turno a la vez por clasificación (bodega y andén no cuentan), solo
 *    entre turnos fuera de planificación: el calendario semanal no cuenta.
 *  - El Guardia marca el arribo hasta 10 min tarde; después, nada (ver
 *    HorarioEntregaProveedor::excedeTolerancia y HorarioEntregaService).
 *
 * LA SEMANA (el "ciclo"): BC borra sus turnos cada domingo a la 1:00, así
 * que un ciclo va del domingo 01:00 al domingo siguiente 01:00, y "Lunes"
 * es el lunes que sigue a ese domingo. BC manda la fecha que calculó y acá
 * se comprueba que sea la de este ciclo: si el borrado del domingo fallara
 * en BC, los turnos viejos NO se convierten en turnos de la semana nueva.
 *
 * Aquí no se borra nada (decisión del usuario: "que se borre de BC pero que
 * quede historial en la base"): un turno que BC ya no manda se da de baja
 * (Activo = 0) solo si todavía no pasó y no tiene arribo; los de días
 * anteriores quedan como estaban.
 */
class FueraPlanificacionService
{
    public const MAX_TURNOS = 500;

    private const OFFSET_DIA = [
        'Domingo' => 0, 'Lunes' => 1, 'Martes' => 2, 'Miercoles' => 3,
        'Jueves' => 4, 'Viernes' => 5, 'Sabado' => 6,
    ];

    public function __construct(
        protected HorarioEntregaService $horarios,
        protected CargaMasivaHorariosService $cargaMasiva,
    ) {
    }

    // ------------------------------------------------------------------
    // Ciclo, ventanas y validación
    // ------------------------------------------------------------------

    /** Domingo 01:00 más reciente (el momento en que BC borró la semana anterior). */
    public function inicioDelCiclo(?CarbonInterface $ahora = null): Carbon
    {
        $ahora = Carbon::parse($ahora ?? now());
        $domingo = $ahora->copy()->startOfDay()->subDays($ahora->dayOfWeek)->setTime(1, 0);

        return $ahora->lessThan($domingo) ? $domingo->subWeek() : $domingo;
    }

    /** Fecha del día de la semana dentro del ciclo actual (Lunes = el lunes después del domingo de inicio). */
    public function fechaDelDia(string $dia, ?CarbonInterface $ahora = null): ?Carbon
    {
        if (! isset(self::OFFSET_DIA[$dia])) {
            return null;
        }

        return $this->inicioDelCiclo($ahora)->startOfDay()->addDays(self::OFFSET_DIA[$dia]);
    }

    /** @return array<string, array<string, array{0: string, 1: string}>> */
    public function ventanas(): array
    {
        return (array) config('portal.fuera_planificacion.ventanas', []);
    }

    /** @return array<int, int> */
    public function duraciones(): array
    {
        return array_map('intval', (array) config('portal.fuera_planificacion.duraciones', [20, 40, 60]));
    }

    /**
     * Por qué un turno no cabe en su ventana, o null si cabe.
     */
    public function motivoFueraDeVentana(string $clasificacion, string $dia, string $horaLlegada, int $duracion): ?string
    {
        $ventana = $this->ventanas()[$clasificacion][$dia] ?? null;
        $etiqueta = str_replace('_', ' ', $clasificacion);
        $diaVisible = strtr($dia, ['Miercoles' => 'miércoles', 'Sabado' => 'sábado']);

        if (! $ventana) {
            return "{$etiqueta} no recibe pedidos fuera de planificación el ".mb_strtolower($diaVisible).'.';
        }

        if (! in_array($duracion, $this->duraciones(), true)) {
            return 'La duración del turno tiene que ser de '.implode(', ', $this->duraciones()).' minutos.';
        }

        [$apertura, $cierre] = $ventana;
        $inicio = self::minutos($horaLlegada);
        $fin = $inicio + $duracion;

        if ($inicio < self::minutos($apertura)) {
            return "La recepción de {$etiqueta} el ".mb_strtolower($diaVisible)." abre a las {$apertura}.";
        }

        if ($fin > self::minutos($cierre)) {
            return "Un turno de {$duracion} min a las {$horaLlegada} termina a las ".self::hora($fin)
                .", después del cierre de las {$cierre}: el personal recibe solo hasta esa hora.";
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Sincronización desde Business Central
    // ------------------------------------------------------------------

    /**
     * Recibe TODOS los turnos que BC tiene para esta semana y deja el portal
     * igual: crea, actualiza o da de baja. Con $confirmar = false solo dice
     * qué haría.
     *
     * @param  array<int, array<string, mixed>>  $turnos
     * @return array{resumen: array<string, int>, turnos: array<int, array<string, mixed>>, bajas: array<int, array<string, mixed>>, inicio_ciclo: string}
     */
    public function sincronizarDesdeBc(Usuario $usuario, int $idEmpresa, array $turnos, bool $confirmar): array
    {
        if (! $usuario->esSistemas($idEmpresa) && ! $usuario->esAdmin($idEmpresa)) {
            throw new AccessDeniedHttpException('Solo Sistemas y Admin pueden sincronizar los pedidos fuera de planificación.');
        }

        if (count($turnos) > self::MAX_TURNOS) {
            throw ValidationException::withMessages(['turnos' => ['Se pueden enviar hasta '.self::MAX_TURNOS.' turnos por vez.']]);
        }

        $ahora = now();
        $inicioCiclo = $this->inicioDelCiclo($ahora);

        [$proveedores, $soloEnBc] = $this->cargaMasiva->proveedoresPorIdentificador(
            $idEmpresa,
            'codigo_bc',
            array_map(fn ($t) => ['identificador' => $t['codigo_bc'] ?? null], $turnos)
        );

        $existentes = HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->whereNotNull('Fecha_Fuera_Planificacion')
            ->whereDate('Fecha_Fuera_Planificacion', '>=', $inicioCiclo->toDateString())
            ->with('proveedor')
            ->orderBy('Id_Horario_Entrega_Proveedor')
            ->get();

        $porIdBc = $existentes->filter(fn ($h) => $h->Id_Turno_Bc !== null)->keyBy('Id_Turno_Bc');
        $arribos = HorarioEntregaEstadoDiario::whereIn('Id_Horario_Entrega_Proveedor', $existentes->pluck('Id_Horario_Entrega_Proveedor'))
            ->whereNotNull('Hora_Arribo_Real')
            ->pluck('Id_Horario_Entrega_Proveedor')
            ->flip();

        // 1) Cada turno por separado: formato, proveedor, semana y ventana.
        $resultados = [];
        $idsVistos = [];

        foreach (array_values($turnos) as $turno) {
            $resultados[] = $this->interpretar($turno, $proveedores, $soloEnBc, $porIdBc, $arribos, $inicioCiclo, $ahora, $idsVistos);
        }

        // 2) Un turno a la vez por clasificación y fecha. Se revisa en el
        // orden en que se crearon en BC (Nº de movimiento): el que ya estaba
        // gana y el nuevo que se le cruza queda con error. Ocupan lugar
        // también los turnos del portal que no se van a tocar (con arribo).
        $ocupados = [];

        foreach ($existentes as $h) {
            if ($h->Activo && isset($arribos[$h->Id_Horario_Entrega_Proveedor]) && ! in_array((int) $h->Id_Turno_Bc, $idsVistos, true)) {
                $ocupados[$h->Clasificacion.'|'.$h->Fecha_Fuera_Planificacion->toDateString()][] = $this->intervalo($h->Hora_Llegada, (int) $h->Tiempo_Permanencia_Min, $h->proveedor?->nombreVisible(), $h->Id_Turno_Bc);
            }
        }

        $orden = array_keys($resultados);
        usort($orden, fn ($a, $b) => ($resultados[$a]['id_bc'] ?? PHP_INT_MAX) <=> ($resultados[$b]['id_bc'] ?? PHP_INT_MAX));

        foreach ($orden as $i) {
            $r = &$resultados[$i];

            if (in_array($r['resultado'], ['error', 'otra_semana'], true)) {
                unset($r);

                continue;
            }

            $clave = $r['clasificacion'].'|'.$r['fecha'];
            $nuevo = $this->intervalo($r['hora_llegada'], $r['duracion_min'], $r['nombre_proveedor'], $r['id_bc']);

            foreach ($ocupados[$clave] ?? [] as $otro) {
                if ($nuevo['inicio'] < $otro['fin'] && $otro['inicio'] < $nuevo['fin']) {
                    $r['resultado'] = 'error';
                    $r['mensaje'] = 'Se cruza con el turno de '.($otro['proveedor'] ?? 'otro proveedor').' de '
                        .self::hora($otro['inicio']).' a '.self::hora($otro['fin']).' (Nº '.$otro['id_bc'].'): en '
                        .str_replace('_', ' ', $r['clasificacion']).' se atiende un turno a la vez.';
                    break;
                }
            }

            if ($r['resultado'] !== 'error') {
                $ocupados[$clave][] = $nuevo;
            }

            unset($r);
        }

        // 3) Lo que el portal tiene de esta semana y BC ya no manda: se da de
        // baja si todavía no llegó la fecha (o es hoy) y no tiene arribo.
        $bajas = $existentes
            ->filter(fn ($h) => $h->Activo
                && ! in_array((int) $h->Id_Turno_Bc, $idsVistos, true)
                && ! isset($arribos[$h->Id_Horario_Entrega_Proveedor])
                && $h->Fecha_Fuera_Planificacion->toDateString() >= $ahora->toDateString())
            ->values();

        if ($confirmar) {
            DB::transaction(function () use ($resultados, $bajas, $porIdBc, $usuario, $idEmpresa, $ahora) {
                foreach ($resultados as &$r) {
                    if (! in_array($r['resultado'], ['crear', 'actualizar'], true)) {
                        continue;
                    }

                    $datos = [
                        'Id_Proveedor' => $r['id_proveedor'],
                        'Clasificacion' => $r['clasificacion'],
                        'Dia_Entrega' => $r['dia_entrega'],
                        'Anden_Puerta' => $r['anden_puerta'],
                        'Hora_Llegada' => $r['hora_llegada'],
                        'Tiempo_Preparacion_Min' => null,
                        'Tiempo_Permanencia_Min' => $r['duracion_min'],
                        'Hora_Salida' => HorarioEntregaService::horaDeSalida($r['hora_llegada'], $r['duracion_min']),
                        'Fecha_Fuera_Planificacion' => $r['fecha'],
                        'Activo' => true,
                        'Modificado_Por' => $usuario->Id_Usuario,
                        'Fecha_Modificacion' => $ahora,
                    ];

                    if ($r['resultado'] === 'crear') {
                        $nuevo = HorarioEntregaProveedor::create($datos + [
                            'Id_Empresa' => $idEmpresa,
                            'Id_Turno_Bc' => $r['id_bc'],
                            'Creado_Por' => $usuario->Id_Usuario,
                            'Fecha_Creacion' => $ahora,
                        ]);
                        $r['id_horario'] = $nuevo->Id_Horario_Entrega_Proveedor;
                    } else {
                        $porIdBc->get($r['id_bc'])->update($datos);
                    }
                }
                unset($r);

                if ($bajas->isNotEmpty()) {
                    HorarioEntregaProveedor::whereIn('Id_Horario_Entrega_Proveedor', $bajas->pluck('Id_Horario_Entrega_Proveedor'))
                        ->update(['Activo' => 0, 'Modificado_Por' => $usuario->Id_Usuario, 'Fecha_Modificacion' => $ahora]);
                }
            });
        }

        $resumen = ['crear' => 0, 'actualizar' => 0, 'sin_cambio' => 0, 'error' => 0, 'otra_semana' => 0];
        foreach ($resultados as $r) {
            $resumen[$r['resultado']]++;
        }
        $resumen['dar_de_baja'] = $bajas->count();

        return [
            'inicio_ciclo' => $inicioCiclo->format('Y-m-d H:i'),
            'resumen' => $resumen,
            'turnos' => array_map(fn ($r) => array_intersect_key($r, array_flip([
                'id_bc', 'resultado', 'mensaje', 'fecha', 'id_horario', 'hora_salida',
            ])), $resultados),
            'bajas' => $bajas->map(fn ($h) => [
                'id_bc' => $h->Id_Turno_Bc,
                'id_horario' => $h->Id_Horario_Entrega_Proveedor,
                'proveedor' => $h->proveedor?->nombreVisible(),
                'fecha' => $h->Fecha_Fuera_Planificacion->toDateString(),
                'hora_llegada' => $h->Hora_Llegada,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $turno
     * @param  array<string, mixed>  $proveedores  código BC -> Proveedor
     * @param  array<string, string>  $soloEnBc  código BC -> nombre en BC (no está en el portal)
     * @param  array<int, int>  $idsVistos
     * @return array<string, mixed>
     */
    private function interpretar(array $turno, array $proveedores, array $soloEnBc, Collection $porIdBc, Collection $arribos, Carbon $inicioCiclo, Carbon $ahora, array &$idsVistos): array
    {
        $idBc = filter_var($turno['id_bc'] ?? null, FILTER_VALIDATE_INT);
        $r = ['id_bc' => $idBc === false ? null : $idBc, 'resultado' => 'error', 'mensaje' => null, 'fecha' => null, 'id_horario' => null];
        $error = function (string $mensaje) use (&$r) {
            $r['mensaje'] = $mensaje;

            return $r;
        };

        if ($r['id_bc'] === null || $r['id_bc'] <= 0) {
            return $error('El turno no trae su número de Business Central.');
        }

        if (in_array($r['id_bc'], $idsVistos, true)) {
            return $error('El turno viene repetido en el envío.');
        }
        $idsVistos[] = $r['id_bc'];

        $clasificacion = $this->clasificacion($turno['clasificacion'] ?? null);
        $dia = $this->dia($turno['dia_entrega'] ?? null);
        $hora = $this->horaValida($turno['hora_llegada'] ?? null);
        $duracion = filter_var($turno['duracion_min'] ?? null, FILTER_VALIDATE_INT);
        $fecha = $this->fechaValida($turno['fecha'] ?? null);
        $codigo = mb_strtoupper(trim((string) ($turno['codigo_bc'] ?? '')));

        if (! $clasificacion) {
            return $error('Clasificación inválida: tiene que ser Perecibles, No Perecibles o Fruver.');
        }
        if (! $dia) {
            return $error('Día de entrega inválido.');
        }
        if (! $hora) {
            return $error('Hora de llegada inválida (formato HH:MM).');
        }
        if ($duracion === false) {
            return $error('Duración inválida.');
        }
        if (! $fecha) {
            return $error('El turno no trae su fecha (AAAA-MM-DD).');
        }

        $r += [
            'clasificacion' => $clasificacion,
            'dia_entrega' => $dia,
            'hora_llegada' => $hora,
            'duracion_min' => $duracion,
            'anden_puerta' => mb_substr(mb_strtoupper(trim((string) ($turno['anden_puerta'] ?? ''))), 0, 20) ?: null,
            'hora_salida' => HorarioEntregaService::horaDeSalida($hora, $duracion),
        ];
        $r['fecha'] = $fecha->toDateString();

        // Un turno de una semana anterior (BC todavía no lo borró): no se
        // crea ni se toca. No es un error de quien lo cargó.
        if ($fecha->lessThan($inicioCiclo->copy()->startOfDay())) {
            $r['resultado'] = 'otra_semana';
            $r['mensaje'] = 'Es de una semana anterior: no se envía.';

            return $r;
        }

        $esperada = $this->fechaDelDia($dia, $ahora);
        if (! $esperada || ! $fecha->isSameDay($esperada)) {
            return $error("La fecha {$r['fecha']} no es el ".mb_strtolower($dia).' de esta semana ('.$esperada?->toDateString().').');
        }

        $proveedor = $proveedores[$codigo] ?? null;
        if (! $proveedor) {
            return $error($codigo === ''
                ? 'Falta el código BC del proveedor.'
                : (isset($soloEnBc[$codigo])
                    ? "El código BC {$codigo} es de {$soloEnBc[$codigo]} en Business Central, pero ese proveedor no está registrado en el portal. Créale la cuenta primero."
                    : "El código BC {$codigo} no está en los proveedores de esta empresa ni en la copia de Business Central."));
        }
        $r['id_proveedor'] = (int) $proveedor->Id_Proveedor;
        $r['nombre_proveedor'] = $proveedor->nombreVisible();

        if ($motivo = $this->motivoFueraDeVentana($clasificacion, $dia, $hora, $duracion)) {
            return $error($motivo);
        }

        $existente = $porIdBc->get($r['id_bc']);
        $cambia = ! $existente
            || ! $existente->Activo
            || (int) $existente->Id_Proveedor !== $r['id_proveedor']
            || $existente->Clasificacion !== $clasificacion
            || $existente->Dia_Entrega !== $dia
            || $existente->Fecha_Fuera_Planificacion?->toDateString() !== $r['fecha']
            || (string) $existente->Anden_Puerta !== (string) $r['anden_puerta']
            || $existente->Hora_Llegada !== $hora
            || (int) $existente->Tiempo_Permanencia_Min !== $duracion;

        if (! $cambia) {
            $r['resultado'] = 'sin_cambio';
            $r['id_horario'] = $existente->Id_Horario_Entrega_Proveedor;

            return $r;
        }

        if ($existente && isset($arribos[$existente->Id_Horario_Entrega_Proveedor])) {
            return $error('El proveedor ya llegó a este turno: no se puede cambiar.');
        }

        $cita = $fecha->copy()->setTimeFromTimeString($hora);
        if ($cita->lessThan($ahora)) {
            return $error("El turno del {$r['fecha']} a las {$hora} ya pasó: no se puede crear ni cambiar.");
        }

        $r['resultado'] = $existente ? 'actualizar' : 'crear';
        $r['id_horario'] = $existente?->Id_Horario_Entrega_Proveedor;

        return $r;
    }

    // ------------------------------------------------------------------
    // Pantalla del portal (solo lectura)
    // ------------------------------------------------------------------

    /**
     * Los turnos de la semana en curso, para la sección "Pedidos fuera de
     * planificación" del portal. Solo lectura: se administran en BC.
     *
     * @return array<string, mixed>
     */
    public function semana(Usuario $usuario, int $idEmpresa, ?string $clasificacion = null): array
    {
        $this->horarios->verificarAccesoLectura($usuario, $idEmpresa);

        $ahora = now();
        $inicio = $this->inicioDelCiclo($ahora);

        $query = HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->whereNotNull('Fecha_Fuera_Planificacion')
            ->whereDate('Fecha_Fuera_Planificacion', '>=', $inicio->toDateString())
            ->with('proveedor');

        if ($clasificacion) {
            $query->where('Clasificacion', $clasificacion);
        }

        $turnos = $query->get();
        $estados = HorarioEntregaEstadoDiario::whereIn('Id_Horario_Entrega_Proveedor', $turnos->pluck('Id_Horario_Entrega_Proveedor'))
            ->get()
            ->keyBy('Id_Horario_Entrega_Proveedor');

        $filas = $this->horarios->serializarVarios($turnos)
            ->map(function (array $fila) use ($turnos, $estados, $ahora) {
                $h = $turnos->firstWhere('Id_Horario_Entrega_Proveedor', $fila['id_horario_entrega_proveedor']);
                $estado = $estados->get($h->Id_Horario_Entrega_Proveedor);

                return [
                    ...$fila,
                    'estado' => $this->horarios->estadoDeLaCita($h, $estado, $ahora),
                    'hora_arribo_real' => $estado?->Hora_Arribo_Real?->format('H:i'),
                ];
            })
            ->sortBy(fn (array $f) => $f['fecha_fuera_planificacion'].' '.$f['hora_llegada'])
            ->values();

        return [
            'inicio_ciclo' => $inicio->format('Y-m-d H:i'),
            'fin_ciclo' => $inicio->copy()->addWeek()->format('Y-m-d H:i'),
            'tolerancia_minutos' => (int) config('portal.fuera_planificacion.tolerancia_minutos', 10),
            'duraciones' => $this->duraciones(),
            'ventanas' => collect($this->ventanas())
                ->map(fn ($dias, $clase) => collect($dias)->map(fn ($v, $dia) => [
                    'dia' => $dia,
                    'fecha' => $this->fechaDelDia($dia, $ahora)?->toDateString(),
                    'apertura' => $v[0],
                    'cierre' => $v[1],
                ])->values())
                ->all(),
            'turnos' => $filas,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{inicio: int, fin: int, proveedor: ?string, id_bc: ?int} */
    private function intervalo(string $hora, int $duracion, ?string $proveedor, ?int $idBc): array
    {
        $inicio = self::minutos($hora);

        return ['inicio' => $inicio, 'fin' => $inicio + $duracion, 'proveedor' => $proveedor, 'id_bc' => $idBc];
    }

    private static function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', array_pad(explode(':', $hora), 2, '0'));

        return $h * 60 + $m;
    }

    private static function hora(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }

    private function clasificacion(mixed $valor): ?string
    {
        $letras = preg_replace('/[^A-Z]/', '', mb_strtoupper(trim((string) $valor)));

        return match ($letras) {
            'PERECIBLES' => HorarioEntregaProveedor::PERECIBLES,
            'NOPERECIBLES' => HorarioEntregaProveedor::NO_PERECIBLES,
            'FRUVER' => HorarioEntregaProveedor::FRUVER,
            default => null,
        };
    }

    private function dia(mixed $valor): ?string
    {
        $letras = preg_replace('/[^A-Z]/', '', strtr(mb_strtoupper(trim((string) $valor)), ['É' => 'E', 'Á' => 'A']));

        foreach (HorarioEntregaProveedor::DIAS as $dia) {
            if (mb_strtoupper($dia) === $letras) {
                return $dia;
            }
        }

        return null;
    }

    private function horaValida(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        if (! preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $texto, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    private function fechaValida(mixed $valor): ?Carbon
    {
        $texto = trim((string) $valor);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto)) {
            return null;
        }

        try {
            $fecha = Carbon::createFromFormat('!Y-m-d', $texto);
        } catch (\Throwable) {
            return null;
        }

        return $fecha && $fecha->format('Y-m-d') === $texto ? $fecha : null;
    }
}
