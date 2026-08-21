<?php

namespace App\Modules\Horarios_Entrega\Services;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Calendario de Horarios de Entrega de Proveedores.
 *
 * Solo VEN el calendario Sistemas, Admin, Compras y Calidad (pedido
 * explícito del usuario). Solo GESTIONAN (CRUD) Sistemas y Admin -> Compras
 * y Calidad quedan de solo lectura, igual que el resto de catálogos
 * administrativos del portal.
 *
 * El "Código Proveedor" que pide el usuario es el código BC
 * (BC_Ficha_Proveedor.Nro_Proveedor), NO se guarda en esta tabla: se
 * resuelve en caliente con el mismo join que ya usa
 * ProductoService::sincronizarDesdeBC (Empresa.Empresa_BC +
 * Proveedor.Ruc = BC_Ficha_Proveedor.Nro_Identificacion). Así, si el
 * proveedor cambia de RUC o BC actualiza su ficha, el calendario no queda
 * con un código viejo guardado a mano.
 */
class HorarioEntregaService
{
    private const ORDEN_DIAS = [
        'Lunes' => 1, 'Martes' => 2, 'Miercoles' => 3, 'Jueves' => 4,
        'Viernes' => 5, 'Sabado' => 6, 'Domingo' => 7,
    ];

    // Carbon::dayOfWeek: 0 = Domingo .. 6 = Sábado (igual que
    // Date.getDay() en el front, ver ModoTvHorariosPage/HOY_A_DIA).
    private const DIA_POR_INDICE = [
        0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles',
        4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado',
    ];

    // Estados del seguimiento en vivo (pedido explícito del usuario, "como
    // funcionaría un aeropuerto"). Programado/Atrasado/En_Recepcion se
    // CALCULAN al vuelo, nunca se guardan (ver calcularEstado); solo
    // En_Arribo y Entregado quedan persistidos porque son los 2 eventos
    // que se marcan a mano.
    public const ESTADO_PROGRAMADO = 'Programado';
    public const ESTADO_ATRASADO = 'Atrasado';
    public const ESTADO_EN_ARRIBO = 'En_Arribo';
    public const ESTADO_EN_RECEPCION = 'En_Recepcion';
    public const ESTADO_ENTREGADO = 'Entregado';

    /** Minutos desde que el Guardia marca "arribó" hasta que pasa solo a "En recepción". */
    private const MINUTOS_HASTA_RECEPCION = 5;

    // ------------------------------------------------------------------
    // Permisos
    // ------------------------------------------------------------------

    public function verificarAccesoLectura(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa)
            || $usuario->esAdmin($idEmpresa)
            || $usuario->esCompras($idEmpresa)
            || $usuario->esCalidad($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('No tiene permisos para ver el calendario de horarios de entrega.');
        }
    }

    public function verificarAccesoGestion(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa) || $usuario->esAdmin($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('Solo Sistemas o Admin pueden gestionar el calendario de horarios de entrega.');
        }
    }

    /** Quién puede ver la pantalla de seguimiento en vivo de hoy (Modo TV incluido). */
    public function verificarAccesoOperativo(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa)
            || $usuario->esAdmin($idEmpresa)
            || $usuario->esCompras($idEmpresa)
            || $usuario->esCalidad($idEmpresa)
            || $usuario->esGuardia($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('No tiene permisos para ver el seguimiento de entregas de hoy.');
        }
    }

    private function verificarAccesoMarcarArribo(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa) || $usuario->esAdmin($idEmpresa) || $usuario->esGuardia($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('Solo el Guardia (o Sistemas/Admin) puede marcar la llegada de un proveedor.');
        }
    }

    private function verificarAccesoMarcarEntregado(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa) || $usuario->esAdmin($idEmpresa) || $usuario->esCompras($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('Solo Compras (o Sistemas/Admin) puede marcar una entrega como completada.');
        }
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    /**
     * Todos los horarios activos de la empresa (o de una sola
     * clasificación si se pide), con el proveedor y su código BC ya
     * resueltos, ordenados por día de la semana y hora de llegada -> es
     * el orden natural para dibujar el calendario y el Modo TV.
     */
    public function listar(Usuario $usuario, int $idEmpresa, ?string $clasificacion = null): Collection
    {
        $this->verificarAccesoLectura($usuario, $idEmpresa);

        $query = HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->with('proveedor');

        if ($clasificacion) {
            $query->where('Clasificacion', $clasificacion);
        }

        $horarios = $query->get();

        $codigosBc = $this->resolverCodigosBc($idEmpresa, $horarios->pluck('proveedor')->filter());

        return $horarios
            ->map(fn (HorarioEntregaProveedor $h) => $this->serializar($h, $codigosBc))
            ->sort(function ($a, $b) {
                $ordenDia = (self::ORDEN_DIAS[$a['dia_entrega']] ?? 99) <=> (self::ORDEN_DIAS[$b['dia_entrega']] ?? 99);

                return $ordenDia !== 0 ? $ordenDia : strcmp($a['hora_llegada'] ?? '', $b['hora_llegada'] ?? '');
            })
            ->values();
    }

    /**
     * Seguimiento en vivo de HOY: solo los horarios de la empresa cuyo
     * Dia_Entrega coincide con el día de hoy, con el estado ya calculado
     * (Programado/Atrasado/En_Arribo/En_Recepcion/Entregado) y SIN los ya
     * "Entregado" -> pedido explícito del usuario: "los que son
     * finalizados ya no deben salir". Ordenado por hora de llegada
     * ascendente (el próximo primero, como un tablero de aeropuerto).
     */
    public function listarDeHoy(Usuario $usuario, int $idEmpresa, ?string $clasificacion = null): Collection
    {
        $this->verificarAccesoOperativo($usuario, $idEmpresa);

        $hoy = now();
        $diaHoy = self::DIA_POR_INDICE[$hoy->dayOfWeek];

        $query = HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->where('Dia_Entrega', $diaHoy)
            ->with('proveedor');

        if ($clasificacion) {
            $query->where('Clasificacion', $clasificacion);
        }

        $horarios = $query->get();

        $codigosBc = $this->resolverCodigosBc($idEmpresa, $horarios->pluck('proveedor')->filter());

        $estadosDelDia = HorarioEntregaEstadoDiario::whereIn('Id_Horario_Entrega_Proveedor', $horarios->pluck('Id_Horario_Entrega_Proveedor'))
            ->whereDate('Fecha', $hoy->toDateString())
            ->get()
            ->keyBy('Id_Horario_Entrega_Proveedor');

        return $horarios
            ->map(function (HorarioEntregaProveedor $h) use ($codigosBc, $estadosDelDia, $hoy) {
                $estadoDiario = $estadosDelDia->get($h->Id_Horario_Entrega_Proveedor);

                return [
                    ...$this->serializar($h, $codigosBc),
                    'estado' => $this->calcularEstado($h, $estadoDiario, $hoy),
                    'hora_arribo_real' => $estadoDiario?->Hora_Arribo_Real?->format('H:i'),
                    'hora_entregado_real' => $estadoDiario?->Hora_Entregado_Real?->format('H:i'),
                ];
            })
            ->filter(fn (array $fila) => $fila['estado'] !== self::ESTADO_ENTREGADO)
            ->sort(fn ($a, $b) => strcmp($a['hora_llegada'] ?? '', $b['hora_llegada'] ?? ''))
            ->values();
    }

    /**
     * El Guardia marca que el proveedor llegó físicamente. Si ya estaba
     * marcado, no se pisa el timestamp real -> se avisa en vez de
     * silenciosamente actualizarlo, para no perder la hora real de la
     * primera marca.
     */
    public function marcarArribo(Usuario $usuario, int $idEmpresa, HorarioEntregaProveedor $horario): array
    {
        $this->verificarAccesoMarcarArribo($usuario, $idEmpresa);
        $this->verificarPertenece($idEmpresa, $horario);

        $hoy = now();

        $estadoDiario = HorarioEntregaEstadoDiario::firstOrNew([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => $hoy->toDateString(),
        ]);

        if ($estadoDiario->exists && $estadoDiario->Hora_Arribo_Real) {
            throw ValidationException::withMessages([
                'horario' => ['Este proveedor ya fue marcado como arribado hoy, a las ' . $estadoDiario->Hora_Arribo_Real->format('H:i') . '.'],
            ]);
        }

        $estadoDiario->fill([
            'Hora_Arribo_Real' => $hoy,
            'Marcado_Arribo_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => $estadoDiario->Fecha_Creacion ?? $hoy,
            'Fecha_Modificacion' => $hoy,
        ])->save();

        return $this->serializarEstadoDiario($horario, $estadoDiario, $hoy);
    }

    /** Compras marca la entrega como completada -> requiere que el Guardia ya haya marcado el arribo. */
    public function marcarEntregado(Usuario $usuario, int $idEmpresa, HorarioEntregaProveedor $horario): array
    {
        $this->verificarAccesoMarcarEntregado($usuario, $idEmpresa);
        $this->verificarPertenece($idEmpresa, $horario);

        $hoy = now();

        $estadoDiario = HorarioEntregaEstadoDiario::where('Id_Horario_Entrega_Proveedor', $horario->Id_Horario_Entrega_Proveedor)
            ->whereDate('Fecha', $hoy->toDateString())
            ->first();

        if (! $estadoDiario || ! $estadoDiario->Hora_Arribo_Real) {
            throw ValidationException::withMessages([
                'horario' => ['Todavía no se ha marcado la llegada de este proveedor hoy.'],
            ]);
        }

        if ($estadoDiario->Hora_Entregado_Real) {
            throw ValidationException::withMessages([
                'horario' => ['Esta entrega ya fue marcada como completada, a las ' . $estadoDiario->Hora_Entregado_Real->format('H:i') . '.'],
            ]);
        }

        $estadoDiario->fill([
            'Hora_Entregado_Real' => $hoy,
            'Marcado_Entregado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => $hoy,
        ])->save();

        return $this->serializarEstadoDiario($horario, $estadoDiario, $hoy);
    }

    /**
     * Programado -> Atrasado (solo se calcula, nunca se guarda: ya pasó
     * la Hora_Llegada programada y el Guardia todavía no marcó nada) ->
     * En_Arribo (el Guardia marcó, se guarda Hora_Arribo_Real) ->
     * En_Recepcion (se calcula: ya pasaron los 5 minutos desde el
     * arribo) -> Entregado (Compras marcó, se guarda Hora_Entregado_Real).
     */
    private function calcularEstado(HorarioEntregaProveedor $h, ?HorarioEntregaEstadoDiario $estadoDiario, Carbon $ahora): string
    {
        if ($estadoDiario?->Hora_Entregado_Real) {
            return self::ESTADO_ENTREGADO;
        }

        if ($estadoDiario?->Hora_Arribo_Real) {
            $minutosDesdeArribo = $estadoDiario->Hora_Arribo_Real->diffInMinutes($ahora);

            return $minutosDesdeArribo >= self::MINUTOS_HASTA_RECEPCION
                ? self::ESTADO_EN_RECEPCION
                : self::ESTADO_EN_ARRIBO;
        }

        $horaLlegadaHoy = Carbon::parse($ahora->toDateString() . ' ' . $h->Hora_Llegada);

        return $ahora->greaterThan($horaLlegadaHoy) ? self::ESTADO_ATRASADO : self::ESTADO_PROGRAMADO;
    }

    private function serializarEstadoDiario(HorarioEntregaProveedor $horario, HorarioEntregaEstadoDiario $estadoDiario, Carbon $ahora): array
    {
        $codigosBc = $this->resolverCodigosBc($horario->Id_Empresa, collect([$horario->proveedor])->filter());

        return [
            ...$this->serializar($horario->load('proveedor'), $codigosBc),
            'estado' => $this->calcularEstado($horario, $estadoDiario, $ahora),
            'hora_arribo_real' => $estadoDiario->Hora_Arribo_Real?->format('H:i'),
            'hora_entregado_real' => $estadoDiario->Hora_Entregado_Real?->format('H:i'),
        ];
    }

    /** Proveedores activos de la empresa, para el selector del CRUD. */
    public function proveedoresDisponibles(Usuario $usuario, int $idEmpresa): Collection
    {
        $this->verificarAccesoGestion($usuario, $idEmpresa);

        $proveedores = Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->orderBy('Razon_Social')
            ->get(['Id_Proveedor', 'Razon_Social', 'Nombre_Comercial', 'Ruc']);

        $codigosBc = $this->resolverCodigosBc($idEmpresa, $proveedores);

        return $proveedores->map(fn (Proveedor $p) => [
            'id_proveedor' => $p->Id_Proveedor,
            'nombre' => $p->Nombre_Comercial ?: $p->Razon_Social,
            'codigo_bc' => $codigosBc[$p->Ruc] ?? null,
        ])->values();
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    public function crear(Usuario $usuario, int $idEmpresa, array $datos): HorarioEntregaProveedor
    {
        $this->verificarAccesoGestion($usuario, $idEmpresa);
        $validados = $this->validar($idEmpresa, $datos);

        $horario = HorarioEntregaProveedor::create([
            ...$validados,
            'Id_Empresa' => $idEmpresa,
            'Activo' => true,
            'Creado_Por' => $usuario->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);

        return $horario->load('proveedor');
    }

    public function actualizar(Usuario $usuario, int $idEmpresa, HorarioEntregaProveedor $horario, array $datos): HorarioEntregaProveedor
    {
        $this->verificarAccesoGestion($usuario, $idEmpresa);
        $this->verificarPertenece($idEmpresa, $horario);

        $validados = $this->validar($idEmpresa, $datos);

        $horario->update([
            ...$validados,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ]);

        return $horario->load('proveedor');
    }

    public function eliminar(Usuario $usuario, int $idEmpresa, HorarioEntregaProveedor $horario): void
    {
        $this->verificarAccesoGestion($usuario, $idEmpresa);
        $this->verificarPertenece($idEmpresa, $horario);

        // Soft-delete, mismo criterio que ClaseProveedorController: nunca
        // se borra físico, por si se necesita auditar/recuperar.
        $horario->update([
            'Activo' => false,
            'Modificado_Por' => $usuario->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ]);
    }

    private function verificarPertenece(int $idEmpresa, HorarioEntregaProveedor $horario): void
    {
        if ((int) $horario->Id_Empresa !== $idEmpresa) {
            throw new AccessDeniedHttpException('Ese horario no pertenece a la empresa activa.');
        }
    }

    private function validar(int $idEmpresa, array $datos): array
    {
        $validados = validator($datos, [
            'id_proveedor' => [
                'required', 'integer',
                Rule::exists('Proveedor', 'Id_Proveedor')->where('Id_Empresa', $idEmpresa),
            ],
            'clasificacion' => ['required', Rule::in(HorarioEntregaProveedor::CLASIFICACIONES)],
            'dia_entrega' => ['required', Rule::in(HorarioEntregaProveedor::DIAS)],
            'anden_puerta' => ['nullable', 'string', 'max:20'],
            'hora_llegada' => ['required', 'date_format:H:i'],
            'tiempo_preparacion_min' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'tiempo_permanencia_min' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'hora_salida' => ['nullable', 'date_format:H:i'],
        ])->validate();

        return [
            'Id_Proveedor' => $validados['id_proveedor'],
            'Clasificacion' => $validados['clasificacion'],
            'Dia_Entrega' => $validados['dia_entrega'],
            'Anden_Puerta' => $validados['anden_puerta'] ?? null,
            'Hora_Llegada' => $validados['hora_llegada'],
            'Tiempo_Preparacion_Min' => $validados['tiempo_preparacion_min'] ?? null,
            'Tiempo_Permanencia_Min' => $validados['tiempo_permanencia_min'] ?? null,
            'Hora_Salida' => $validados['hora_salida'] ?? null,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, Proveedor>  $proveedores
     * @return array<string, string> Ruc -> código BC (Nro_Proveedor)
     */
    private function resolverCodigosBc(int $idEmpresa, Collection $proveedores): array
    {
        $rucs = $proveedores->pluck('Ruc')->filter()->unique()->values();

        if ($rucs->isEmpty()) {
            return [];
        }

        $empresa = Empresa::find($idEmpresa);

        if (! $empresa || ! $empresa->Empresa_BC) {
            return [];
        }

        return DB::table('BC_Ficha_Proveedor')
            ->where('Empresa', trim($empresa->Empresa_BC))
            ->whereIn('Nro_Identificacion', $rucs)
            ->pluck('Nro_Proveedor', 'Nro_Identificacion')
            ->all();
    }

    private function serializar(HorarioEntregaProveedor $h, array $codigosBc): array
    {
        $proveedor = $h->proveedor;

        return [
            'id_horario_entrega_proveedor' => $h->Id_Horario_Entrega_Proveedor,
            'id_proveedor' => $h->Id_Proveedor,
            'codigo_proveedor' => $proveedor ? ($codigosBc[$proveedor->Ruc] ?? null) : null,
            'nombre_proveedor' => $proveedor ? ($proveedor->Nombre_Comercial ?: $proveedor->Razon_Social) : null,
            'clasificacion' => $h->Clasificacion,
            'dia_entrega' => $h->Dia_Entrega,
            'anden_puerta' => $h->Anden_Puerta,
            'hora_llegada' => $h->Hora_Llegada,
            'tiempo_preparacion_min' => $h->Tiempo_Preparacion_Min,
            'tiempo_permanencia_min' => $h->Tiempo_Permanencia_Min,
            'hora_salida' => $h->Hora_Salida,
        ];
    }
}
