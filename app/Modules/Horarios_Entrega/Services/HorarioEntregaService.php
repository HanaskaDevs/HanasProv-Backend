<?php

namespace App\Modules\Horarios_Entrega\Services;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Configuraciones\Models\Configuracion;
use App\Modules\Horarios_Entrega\Mail\SolicitudAprobacionArriboMail;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaEstadoDiario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Models\SolicitudAprobacionArribo;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\Proveedor;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
 *
 * FLUJO DE ESTADOS (revisión completa, pedido explícito del usuario, "como
 * funcionaría un aeropuerto" + aprobación de arribos tardíos):
 *
 *   Programado -> Atrasado (ya pasó Hora_Llegada, el Guardia no marcó nada)
 *       -> Rechazado (pasaron config('portal.minutos_atrasado_a_rechazado'),
 *          30 min por defecto, sin que el Guardia marque arribo)
 *
 *   Desde Programado/Atrasado: el Guardia marca arribo directo -> Arribo.
 *   Desde Rechazado: el Guardia YA NO puede marcar arribo directo -> solo
 *       puede mandar una Solicitud_Aprobacion_Arribo (correo a
 *       config('portal.email_aprobacion_arribo')). Si Calidad la aprueba,
 *       recién ahí pasa a Arribo (con la Hora_Arribo_Real = el momento en
 *       que el Guardia mandó la solicitud, no el momento de la aprobación).
 *       Si la rechaza, el horario queda Rechazado en firme.
 *
 *   Arribo -> En_Recepcion -> Recibido: estos 2 pasos YA NO se marcan a
 *       mano (antes lo hacía Compras) -> los dispara en automático
 *       SincronizarEstadosSighCommand, leyendo SIGH (servidor 192.168.1.135,
 *       conexión sqlsrv_sigh, solo lectura):
 *         - En_Recepcion: apenas aparece un SIGH.DocumentoInv.ItemPedido que
 *           matchea un SIGH.Documento.Item cuyo NroDocumentoBC resuelve al
 *           pedido de este proveedor.
 *         - Recibido: cuando ese mismo SIGH.Documento.EstadoPedido = 'N'.
 *       Se reusan Hora_Entregado_Real/Marcado_Entregado_Por para "Recibido"
 *       (Marcado_Entregado_Por queda NULL: lo puso el job, no una persona).
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

    public const ESTADO_PROGRAMADO = 'Programado';
    public const ESTADO_ATRASADO = 'Atrasado';
    public const ESTADO_RECHAZADO = 'Rechazado';
    public const ESTADO_ARRIBO = 'Arribo';
    public const ESTADO_EN_RECEPCION = 'En_Recepcion';
    public const ESTADO_RECIBIDO = 'Recibido';

    /**
     * Interruptor de los anuncios por voz del Modo TV. Vive acá, en el
     * módulo que consume la configuración, por el mismo criterio que
     * VencimientoDocumentosService::CLAVE_SUSPENSION_AUTOMATICA: la clave
     * la define quien la usa, y Configuraciones solo la expone para la
     * pantalla de Sistemas -> el nombre de la clave no queda escrito en
     * dos lugares distintos.
     */
    public const CLAVE_ANUNCIOS_VOZ = 'anuncios_voz_modo_tv';

    // ------------------------------------------------------------------
    // Anuncios por voz del Modo TV
    // ------------------------------------------------------------------

    /**
     * ¿El Modo TV debe cantar por voz los cambios de estado?
     *
     * Por defecto ENCENDIDO: si la clave todavía no existe, se asume que
     * sí -> es lo que se pidió y evita que la función quede muda hasta que
     * alguien entre a Configuraciones a prenderla.
     *
     * El interruptor es GLOBAL (una sola fila en Configuracion), no por
     * empresa ni por pantalla: la decisión es "esta bodega usa anuncios o
     * no", y quien la toma es Sistemas desde Configuraciones.
     */
    public function anunciosVozActivos(): bool
    {
        return Configuracion::obtener(self::CLAVE_ANUNCIOS_VOZ, '1') === '1';
    }

    public function definirAnunciosVoz(bool $activo, int $idUsuario): void
    {
        Configuracion::establecer(self::CLAVE_ANUNCIOS_VOZ, $activo ? '1' : '0', $idUsuario);
    }

    // ------------------------------------------------------------------
    // Permisos
    // ------------------------------------------------------------------

    /**
     * Ver el calendario (incluye Modo TV): Sistemas, Admin, Compras y
     * Calidad (pedido explícito del usuario, 27-ago-2026). El CRUD
     * (crear/editar/eliminar horarios) es más restringido, ver
     * verificarAccesoGestion.
     */
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

    /**
     * Gestión (CRUD) del calendario: Sistemas, Admin y Compras (pedido
     * explícito del usuario, 27-ago-2026). Calidad se queda solo con
     * lectura (ver verificarAccesoLectura) -> puede VER el calendario
     * pero no crear/editar/eliminar horarios.
     */
    public function verificarAccesoGestion(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa) || $usuario->esAdmin($idEmpresa) || $usuario->esCompras($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('Solo Sistemas, Admin o Compras pueden gestionar el calendario de horarios de entrega.');
        }
    }

    /**
     * Este endpoint (/horarios-entrega/hoy) lo comparten 2 pantallas con
     * audiencias distintas -> acá se deja pasar la UNIÓN de las dos, y
     * cada pantalla filtra más estricto por su cuenta en el frontend
     * (RoleRoute):
     *   - Modo TV (dentro de Calendario): Sistemas, Admin, Calidad, Compras.
     *   - Seguimiento de hoy: SOLO Sistemas y Guardia (pedido explícito
     *     del usuario, 27-ago-2026 -> Admin/Compras/Calidad ya NO entran
     *     a esta pantalla, aunque el endpoint técnicamente los deje).
     */
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

    /**
     * Cambios de estado (marcar arribo, solicitar aprobación): SOLO
     * Guardia, Sistemas y Calidad (pedido explícito del usuario). Admin y
     * Compras quedaron fuera -> ya no marcan nada a mano, Compras además
     * perdió "marcar entregado" porque ahora es 100% automático vía SIGH.
     */
    private function verificarAccesoCambiarEstado(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa) || $usuario->esGuardia($idEmpresa) || $usuario->esCalidad($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('Solo Guardia, Calidad o Sistemas pueden cambiar el estado de una entrega.');
        }
    }

    /**
     * Aprobar/rechazar una solicitud de arribo tardío: SOLO Calidad
     * (Valeria) o Sistemas como respaldo -> pedido explícito del usuario,
     * "solo ella va a tener ese control de aprobación". Admin queda
     * afuera a propósito.
     */
    private function verificarAccesoResolverAprobacion(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->esSistemas($idEmpresa) || $usuario->esCalidad($idEmpresa);

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('Solo Calidad puede aprobar o rechazar una solicitud de arribo.');
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
     * Calendario de UN SOLO proveedor -> lo usa la sección de Pedidos del
     * proveedor (pedido explícito del usuario: "el calendario debe
     * mostrarle al proveedor el horario y fechas que tiene para
     * entregar"). Sin permiso de lectura interno: cualquier usuario
     * Proveedor ve solo SU PROPIO horario, nunca el de otro.
     */
    public function misHorarios(Usuario $usuario, int $idEmpresa): Collection
    {
        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Este calendario es solo para usuarios Proveedor.');
        }

        $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresa)->first();

        if (! $proveedor) {
            return collect();
        }

        $horarios = HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->with('proveedor')
            ->get();

        $codigosBc = $this->resolverCodigosBc($idEmpresa, $horarios->pluck('proveedor')->filter());

        return $horarios
            ->map(fn (HorarioEntregaProveedor $h) => $this->serializar($h, $codigosBc))
            ->sort(fn ($a, $b) => (self::ORDEN_DIAS[$a['dia_entrega']] ?? 99) <=> (self::ORDEN_DIAS[$b['dia_entrega']] ?? 99))
            ->values();
    }

    /**
     * Seguimiento en vivo de HOY: solo los horarios de la empresa cuyo
     * Dia_Entrega coincide con el día de hoy, con el estado ya calculado
     * y SIN los ya "Recibido" -> pedido explícito del usuario: "los que
     * son finalizados ya no deben salir". Ordenado por hora de llegada
     * ascendente (el próximo primero, como un tablero de aeropuerto).
     */
    /**
     * $incluirRecibidos lo usa SOLO el Modo TV. El resto de las pantallas
     * (Seguimiento de hoy) siguen sin ver los Recibido, como hasta ahora.
     *
     * POR QUÉ HIZO FALTA: los anuncios por voz del Modo TV se disparan
     * comparando el estado de cada fila contra el de la lectura anterior.
     * Con los Recibido filtrados, ese proveedor no cambiaba de estado: la
     * fila DESAPARECÍA, y "desapareció" no alcanza para anunciar que
     * entregó (una fila también se va al cambiar de franja horaria o al
     * recargar, y ahí se cantarían entregas que nunca pasaron). Devolviendo
     * el estado real, el cambio Arribo/En_Recepcion -> Recibido es un
     * cambio de estado normal y se anuncia como cualquier otro.
     */
    public function listarDeHoy(Usuario $usuario, int $idEmpresa, ?string $clasificacion = null, bool $incluirRecibidos = false): Collection
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

        $solicitudesPendientes = SolicitudAprobacionArribo::whereIn('Id_Horario_Entrega_Proveedor', $horarios->pluck('Id_Horario_Entrega_Proveedor'))
            ->whereDate('Fecha', $hoy->toDateString())
            ->where('Estado', 'Pendiente')
            ->get()
            ->keyBy('Id_Horario_Entrega_Proveedor');

        return $horarios
            ->map(function (HorarioEntregaProveedor $h) use ($codigosBc, $estadosDelDia, $solicitudesPendientes, $hoy) {
                $estadoDiario = $estadosDelDia->get($h->Id_Horario_Entrega_Proveedor);

                return [
                    ...$this->serializar($h, $codigosBc),
                    'estado' => $this->calcularEstado($h, $estadoDiario, $hoy),
                    'hora_arribo_real' => $estadoDiario?->Hora_Arribo_Real?->format('H:i'),
                    'hora_recepcion_real' => $estadoDiario?->Hora_Recepcion_Real?->format('H:i'),
                    'hora_recibido_real' => $estadoDiario?->Hora_Entregado_Real?->format('H:i'),
                    // Pedido explícito del usuario: mostrar en la pantalla
                    // de seguimiento con qué documento de SIGH se hizo el
                    // match automático (para poder ubicarlo del lado de
                    // SIGH si hace falta revisar algo). Null hasta que
                    // llegue a En_Recepcion.
                    'nro_documento_bc' => $estadoDiario?->Nro_Documento_Bc,
                    'tiene_solicitud_pendiente' => $solicitudesPendientes->has($h->Id_Horario_Entrega_Proveedor),
                ];
            })
            ->filter(fn (array $fila) => $incluirRecibidos || $fila['estado'] !== self::ESTADO_RECIBIDO)
            ->sort(fn ($a, $b) => strcmp($a['hora_llegada'] ?? '', $b['hora_llegada'] ?? ''))
            ->values();
    }

    /**
     * Programado -> Atrasado -> Rechazado (se calculan solos, nunca se
     * guardan) -> Arribo (Guardia marcó directo, o Calidad aprobó una
     * solicitud) -> En_Recepcion -> Recibido (estos 2 últimos los pone el
     * job de sincronización con SIGH, ver Hora_Recepcion_Real arriba).
     */
    private function calcularEstado(HorarioEntregaProveedor $h, ?HorarioEntregaEstadoDiario $estadoDiario, Carbon $ahora): string
    {
        if ($estadoDiario?->Hora_Entregado_Real) {
            return self::ESTADO_RECIBIDO;
        }

        if ($estadoDiario?->Hora_Recepcion_Real) {
            return self::ESTADO_EN_RECEPCION;
        }

        if ($estadoDiario?->Hora_Arribo_Real) {
            return self::ESTADO_ARRIBO;
        }

        $horaLlegadaHoy = Carbon::parse($ahora->toDateString() . ' ' . $h->Hora_Llegada);

        if ($ahora->lessThanOrEqualTo($horaLlegadaHoy)) {
            return self::ESTADO_PROGRAMADO;
        }

        $minutosTarde = $horaLlegadaHoy->diffInMinutes($ahora);
        $limiteRechazo = (int) config('portal.minutos_atrasado_a_rechazado', 30);

        return $minutosTarde >= $limiteRechazo ? self::ESTADO_RECHAZADO : self::ESTADO_ATRASADO;
    }

    private function serializarEstadoDiario(HorarioEntregaProveedor $horario, HorarioEntregaEstadoDiario $estadoDiario, Carbon $ahora): array
    {
        $codigosBc = $this->resolverCodigosBc($horario->Id_Empresa, collect([$horario->proveedor])->filter());

        return [
            ...$this->serializar($horario->load('proveedor'), $codigosBc),
            'estado' => $this->calcularEstado($horario, $estadoDiario, $ahora),
            'hora_arribo_real' => $estadoDiario->Hora_Arribo_Real?->format('H:i'),
            'hora_recepcion_real' => $estadoDiario->Hora_Recepcion_Real?->format('H:i'),
            'hora_recibido_real' => $estadoDiario->Hora_Entregado_Real?->format('H:i'),
            'nro_documento_bc' => $estadoDiario->Nro_Documento_Bc,
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

    /**
     * Pedidos abiertos que ese proveedor debe entregar HOY -> lo usa el
     * modal de "Seguimiento de entregas" para Admin/Compras/Calidad
     * (pedido explícito del usuario: al hacer clic en el registro, ver
     * qué pedidos debe entregar ese proveedor ese día).
     */
    public function pedidosDelDia(Usuario $usuario, int $idEmpresa, HorarioEntregaProveedor $horario): Collection
    {
        $this->verificarAccesoOperativo($usuario, $idEmpresa);
        $this->verificarPertenece($idEmpresa, $horario);

        return PedidoCompra::where('Id_Empresa', $idEmpresa)
            ->where('Id_Proveedor', $horario->Id_Proveedor)
            ->where('Activo', 1)
            ->whereDate('Fecha_Recepcion_Esperada', now()->toDateString())
            ->orderBy('Nro_Pedido')
            ->get(['Id_Pedido_Compra', 'Nro_Pedido', 'Estado', 'Estado_Pedido_BC', 'Fecha_Recepcion_Esperada'])
            ->map(fn (PedidoCompra $p) => [
                'id_pedido_compra' => $p->Id_Pedido_Compra,
                'nro_pedido' => $p->Nro_Pedido,
                'estado' => $p->Estado,
                'estado_pedido_bc' => $p->Estado_Pedido_BC,
                'fecha_recepcion_esperada' => $p->Fecha_Recepcion_Esperada?->format('Y-m-d'),
            ])
            ->values();
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

    // ------------------------------------------------------------------
    // Marcar arribo / solicitud de aprobación
    // ------------------------------------------------------------------

    /**
     * El Guardia (o Sistemas/Calidad) marca que el proveedor llegó
     * físicamente. Solo funciona si el horario está Programado o
     * Atrasado -> si ya cayó a Rechazado, hay que pasar por
     * solicitarAprobacion() en su lugar.
     */
    public function marcarArribo(Usuario $usuario, int $idEmpresa, HorarioEntregaProveedor $horario): array
    {
        $this->verificarAccesoCambiarEstado($usuario, $idEmpresa);
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

        $estadoActual = $this->calcularEstado($horario, $estadoDiario->exists ? $estadoDiario : null, $hoy);

        if ($estadoActual === self::ESTADO_RECHAZADO) {
            throw ValidationException::withMessages([
                'horario' => ['Este horario ya pasó a Rechazado (más de ' . config('portal.minutos_atrasado_a_rechazado', 30) . ' min de atraso). Envía una solicitud de aprobación en su lugar.'],
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

    /**
     * El Guardia pide aprobación para registrar un arribo que ya cayó en
     * Rechazado. Manda correo a Calidad (config('portal.
     * email_aprobacion_arribo')) y queda pendiente hasta que se
     * apruebe/rechace desde /calendario/aprobaciones.
     */
    public function solicitarAprobacion(Usuario $usuario, int $idEmpresa, HorarioEntregaProveedor $horario): SolicitudAprobacionArribo
    {
        $this->verificarAccesoCambiarEstado($usuario, $idEmpresa);
        $this->verificarPertenece($idEmpresa, $horario);

        $hoy = now();

        $estadoDiario = HorarioEntregaEstadoDiario::where('Id_Horario_Entrega_Proveedor', $horario->Id_Horario_Entrega_Proveedor)
            ->whereDate('Fecha', $hoy->toDateString())
            ->first();

        $estadoActual = $this->calcularEstado($horario, $estadoDiario, $hoy);

        if ($estadoActual !== self::ESTADO_RECHAZADO) {
            throw ValidationException::withMessages([
                'horario' => ['Este horario todavía no está Rechazado: puedes marcar el arribo directo.'],
            ]);
        }

        $yaTienePendiente = SolicitudAprobacionArribo::where('Id_Horario_Entrega_Proveedor', $horario->Id_Horario_Entrega_Proveedor)
            ->whereDate('Fecha', $hoy->toDateString())
            ->where('Estado', 'Pendiente')
            ->exists();

        if ($yaTienePendiente) {
            throw ValidationException::withMessages([
                'horario' => ['Ya existe una solicitud de aprobación pendiente para este proveedor hoy.'],
            ]);
        }

        $solicitud = SolicitudAprobacionArribo::create([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => $hoy->toDateString(),
            'Solicitado_Por' => $usuario->Id_Usuario,
            'Fecha_Solicitud' => $hoy,
            'Estado' => 'Pendiente',
        ]);

        $this->notificarSolicitudAprobacion($horario, $usuario, $solicitud);

        return $solicitud;
    }

    private function notificarSolicitudAprobacion(HorarioEntregaProveedor $horario, Usuario $solicitante, SolicitudAprobacionArribo $solicitud): void
    {
        $destinatario = config('portal.email_aprobacion_arribo');

        if (! $destinatario) {
            return;
        }

        $proveedor = $horario->proveedor;
        $urlRevision = rtrim(config('app.frontend_url'), '/') . '/calendario/aprobaciones';

        Mail::to($destinatario)->send(new SolicitudAprobacionArriboMail(
            $proveedor ? ($proveedor->Nombre_Comercial ?: $proveedor->Razon_Social) : 'Proveedor',
            $horario->Hora_Llegada,
            $solicitante->Nombre_Completo,
            $solicitud->Fecha_Solicitud->format('d/m/Y H:i'),
            $urlRevision,
        ));
    }

    /** Calidad: lista las solicitudes de arribo pendientes de la empresa activa. */
    public function listarSolicitudesPendientes(Usuario $usuario, int $idEmpresa): Collection
    {
        $this->verificarAccesoResolverAprobacion($usuario, $idEmpresa);

        return SolicitudAprobacionArribo::whereHas(
            'horario',
            fn ($q) => $q->where('Id_Empresa', $idEmpresa)
        )
            ->where('Estado', 'Pendiente')
            ->with(['horario.proveedor', 'solicitante'])
            ->orderBy('Fecha_Solicitud')
            ->get()
            ->map(fn (SolicitudAprobacionArribo $s) => [
                'id_solicitud_aprobacion_arribo' => $s->Id_Solicitud_Aprobacion_Arribo,
                'id_horario_entrega_proveedor' => $s->Id_Horario_Entrega_Proveedor,
                'nombre_proveedor' => $s->horario?->proveedor
                    ? ($s->horario->proveedor->Nombre_Comercial ?: $s->horario->proveedor->Razon_Social)
                    : null,
                'hora_llegada' => $s->horario?->Hora_Llegada,
                'fecha' => $s->Fecha->format('Y-m-d'),
                'solicitado_por' => $s->solicitante?->Nombre_Completo,
                'fecha_solicitud' => $s->Fecha_Solicitud->format('Y-m-d H:i'),
            ])
            ->values();
    }

    public function aprobarSolicitud(Usuario $usuario, int $idEmpresa, int $idSolicitud): SolicitudAprobacionArribo
    {
        $solicitud = $this->solicitudDeLaEmpresa($usuario, $idEmpresa, $idSolicitud);

        DB::transaction(function () use ($usuario, $solicitud) {
            $estadoDiario = HorarioEntregaEstadoDiario::firstOrNew([
                'Id_Horario_Entrega_Proveedor' => $solicitud->Id_Horario_Entrega_Proveedor,
                'Fecha' => $solicitud->Fecha->toDateString(),
            ]);

            // La Hora_Arribo_Real queda en el momento en que el Guardia
            // avisó (Fecha_Solicitud), no en el momento en que Calidad
            // aprobó -> es la hora real en que el proveedor llegó.
            $estadoDiario->fill([
                'Hora_Arribo_Real' => $estadoDiario->Hora_Arribo_Real ?? $solicitud->Fecha_Solicitud,
                'Marcado_Arribo_Por' => $estadoDiario->Marcado_Arribo_Por ?? $solicitud->Solicitado_Por,
                'Fecha_Creacion' => $estadoDiario->Fecha_Creacion ?? now(),
                'Fecha_Modificacion' => now(),
            ])->save();

            $solicitud->forceFill([
                'Estado' => 'Aprobada',
                'Resuelto_Por' => $usuario->Id_Usuario,
                'Fecha_Resolucion' => now(),
            ])->save();
        });

        return $solicitud->fresh(['horario.proveedor']);
    }

    public function rechazarSolicitud(Usuario $usuario, int $idEmpresa, int $idSolicitud, ?string $motivo): SolicitudAprobacionArribo
    {
        $solicitud = $this->solicitudDeLaEmpresa($usuario, $idEmpresa, $idSolicitud);

        $solicitud->forceFill([
            'Estado' => 'Rechazada',
            'Resuelto_Por' => $usuario->Id_Usuario,
            'Fecha_Resolucion' => now(),
            'Comentario_Resolucion' => $motivo,
        ])->save();

        return $solicitud->fresh(['horario.proveedor']);
    }

    private function solicitudDeLaEmpresa(Usuario $usuario, int $idEmpresa, int $idSolicitud): SolicitudAprobacionArribo
    {
        $this->verificarAccesoResolverAprobacion($usuario, $idEmpresa);

        $solicitud = SolicitudAprobacionArribo::whereHas(
            'horario',
            fn ($q) => $q->where('Id_Empresa', $idEmpresa)
        )
            ->with('horario')
            ->findOrFail($idSolicitud);

        if ($solicitud->Estado !== 'Pendiente') {
            throw ValidationException::withMessages([
                'solicitud' => ['Esta solicitud ya fue resuelta.'],
            ]);
        }

        return $solicitud;
    }

    // ------------------------------------------------------------------
    // Sincronización automática con SIGH (usada solo por
    // SincronizarEstadosSighCommand, sin usuario -> son eventos del
    // sistema, no de una persona).
    // ------------------------------------------------------------------

    /** Horarios de HOY que ya arribaron pero todavía no entraron en recepción. */
    public function estadosEnEsperaDeRecepcion(): Collection
    {
        $hoy = now()->toDateString();

        return HorarioEntregaEstadoDiario::whereDate('Fecha', $hoy)
            ->whereNotNull('Hora_Arribo_Real')
            ->whereNull('Hora_Recepcion_Real')
            ->with('horario.proveedor')
            ->get();
    }

    /** Horarios de HOY ya en recepción, esperando el cierre (Recibido). */
    public function estadosEnEsperaDeRecibido(): Collection
    {
        $hoy = now()->toDateString();

        return HorarioEntregaEstadoDiario::whereDate('Fecha', $hoy)
            ->whereNotNull('Hora_Recepcion_Real')
            ->whereNull('Hora_Entregado_Real')
            ->with('horario.proveedor')
            ->get();
    }

    public function marcarRecepcionAutomatica(HorarioEntregaEstadoDiario $estadoDiario, ?string $nroDocumentoBc): void
    {
        $estadoDiario->forceFill([
            'Hora_Recepcion_Real' => now(),
            'Nro_Documento_Bc' => $nroDocumentoBc,
            'Fecha_Modificacion' => now(),
        ])->save();
    }

    public function marcarRecibidoAutomatico(HorarioEntregaEstadoDiario $estadoDiario): void
    {
        $estadoDiario->forceFill([
            'Hora_Entregado_Real' => now(),
            // NULL a propósito: distingue "lo puso el job" de una marca
            // manual vieja (Marcado_Entregado_Por con un Id_Usuario).
            'Marcado_Entregado_Por' => null,
            'Fecha_Modificacion' => now(),
        ])->save();
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
