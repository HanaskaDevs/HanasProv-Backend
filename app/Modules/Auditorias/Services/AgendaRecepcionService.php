<?php

namespace App\Modules\Auditorias\Services;

use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\CalificacionCampoFicha;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\CalificacionProveedorService;
use App\Shared\FeriadosEcuador;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Responde "¿a qué proveedores hay que calificarles la recepción HOY?".
 *
 * CAMBIO DE FONDO RESPECTO A LA VERSIÓN ANTERIOR. Antes esto repartía a los
 * proveedores a lo largo del año con una fórmula sobre el Id (Id * primo %
 * 365) y le avisaba a Calidad el día que le tocaba a cada uno. Ese diseño
 * tenía un problema que lo invalidaba: agendaba la auditoría en días en los
 * que el proveedor no entregaba nada. No se puede calificar una recepción si
 * no hay recepción.
 *
 * Ahora la regla es la del negocio, y son tres condiciones a la vez:
 *
 *   1. HOY ES DÍA LABORABLE. Ni fin de semana ni feriado (ver
 *      App\Shared\FeriadosEcuador).
 *   2. EL PROVEEDOR TIENE ENTREGA HOY, y tiene que cumplir LAS DOS cosas
 *      (26-oct-2026, pedido del usuario): está en el calendario semanal de
 *      horarios de entrega con el día de hoy Y tiene un pedido de compra con
 *      fecha de recepción esperada hoy. Antes bastaba una de las dos, y la
 *      lista de "le toca hoy" llegaba a 52 proveedores: el calendario dice
 *      que PUEDE entregar ese día, el pedido dice que de verdad trae algo.
 *   3. TODAVÍA NO TIENE SU CALIFICACIÓN DEL AÑO. Es una por año
 *      CALENDARIO, y el plazo se cierra en NOVIEMBRE: todos los proveedores
 *      tienen que estar calificados antes de diciembre, así que diciembre
 *      queda para cerrar rezagados y ya no se genera aviso nuevo.
 *   4. ESTÁ HABILITADO PARA RECEPCIONES (26-oct-2026, pedido del usuario):
 *      su ficha está aprobada (todos los campos aprobados, la misma regla
 *      de Proveedor::estadoGeneralCalificacionFicha) Y tiene al menos un
 *      producto aprobado. Ver idsHabilitadosParaRecepcion.
 *
 * Y de los que cumplen, se proponen COMO MÁXIMO 3 por día (26-oct-2026):
 * Calidad no puede hacer 52 recepciones en un día. Se eligen en el orden del
 * calendario de horarios (el que llega más temprano primero); los demás
 * vuelven a aparecer en su próxima entrega.
 *
 * El efecto práctico es que el aviso aparece en la primera entrega del año
 * del proveedor y se repite en cada entrega hasta que Calidad la registre.
 * Es a propósito: insiste hasta que se haga, en vez de tener una única
 * oportunidad en el año que si se pierde no vuelve.
 */
class AgendaRecepcionService
{
    /**
     * Último mes en el que se avisa. En diciembre ya no se generan avisos
     * nuevos: a esa altura la meta del año tenía que estar cumplida.
     */
    public const ULTIMO_MES_DE_AVISO = 11;

    /** Cuántos proveedores se proponen por día, como máximo (26-oct-2026). */
    public const MAX_PROVEEDORES_POR_DIA = 3;

    // Carbon::dayOfWeek: 0 = Domingo .. 6 = Sábado. Los nombres son los
    // mismos que guarda Horario_Entrega_Proveedor.Dia_Entrega (sin tildes).
    private const DIA_POR_INDICE = [
        0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles',
        4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado',
    ];

    public function __construct(protected FeriadosEcuador $feriados)
    {
    }

    /**
     * Proveedores de una empresa a los que hay que calificarles la recepción
     * hoy. Lo usa el comando que avisa a Calidad a las 5 de la mañana.
     *
     * @return Collection<int, Proveedor>
     */
    public function proveedoresQueTocanHoy(int $idEmpresa, ?CarbonInterface $fecha = null): Collection
    {
        $fecha = $fecha ? $fecha->copy()->startOfDay() : now()->startOfDay();

        if (! $this->esDiaDeAviso($fecha)) {
            return new Collection();
        }

        $idsConEntrega = $this->idsConEntregaEse($idEmpresa, $fecha);

        if ($idsConEntrega === []) {
            return new Collection();
        }

        $yaCalificados = $this->idsYaCalificadosEnElAnio($idEmpresa, (int) $fecha->year);

        $pendientes = array_values(array_intersect(
            array_diff($idsConEntrega, $yaCalificados),
            $this->idsHabilitadosParaRecepcion($idEmpresa)
        ));

        if ($pendientes === []) {
            return new Collection();
        }

        // Orden del calendario: la hora de llegada más temprana de ese día
        // ("HH:MM" ordena bien como texto), y a igual hora, alfabético.
        $horaDeLlegada = HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->where('Dia_Entrega', self::DIA_POR_INDICE[$fecha->dayOfWeek])
            ->whereIn('Id_Proveedor', $pendientes)
            ->get(['Id_Proveedor', 'Hora_Llegada'])
            ->groupBy('Id_Proveedor')
            ->map(fn ($filas) => (string) $filas->min('Hora_Llegada'));

        return Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->whereIn('Id_Proveedor', $pendientes)
            ->get()
            ->sortBy([
                fn (Proveedor $a, Proveedor $b) => strcmp($horaDeLlegada->get($a->Id_Proveedor, '99:99'), $horaDeLlegada->get($b->Id_Proveedor, '99:99')),
                fn (Proveedor $a, Proveedor $b) => strnatcasecmp((string) $a->Razon_Social, (string) $b->Razon_Social),
            ])
            ->take(self::MAX_PROVEEDORES_POR_DIA)
            ->values();
    }

    /**
     * ¿Corresponde generar avisos en esta fecha? Día laborable y dentro de
     * la ventana enero-noviembre.
     */
    public function esDiaDeAviso(CarbonInterface $fecha): bool
    {
        return $this->feriados->esLaborable($fecha)
            && $fecha->month <= self::ULTIMO_MES_DE_AVISO;
    }

    /**
     * Ids de proveedores con entrega esa fecha: en el calendario de horarios
     * con ese día de la semana Y con un pedido de compra con recepción
     * esperada esa fecha (ver la regla 2 del docblock de la clase).
     *
     * @return list<int>
     */
    public function idsConEntregaEse(int $idEmpresa, CarbonInterface $fecha): array
    {
        $porCalendario = $this->consultaCalendario($idEmpresa, $fecha)
            ->distinct()
            ->pluck('Id_Proveedor')
            ->map(fn ($id) => (int) $id)
            ->all();

        $porPedido = $this->consultaPedidos($idEmpresa, $fecha)
            ->distinct()
            ->pluck('Id_Proveedor')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_intersect(array_unique($porCalendario), array_unique($porPedido)));
    }

    /**
     * ¿Este proveedor entrega esa fecha? Por separado lo del calendario y lo
     * del pedido, para poder decir cuál de las dos falta. Lo usan la
     * calificación de recepciones y las auditorías antes de empezar una.
     *
     * @return array{calendario: bool, pedido: bool, tiene_entrega: bool}
     */
    public function entregaDelProveedor(int $idProveedor, int $idEmpresa, CarbonInterface $fecha): array
    {
        $calendario = $this->consultaCalendario($idEmpresa, $fecha)->where('Id_Proveedor', $idProveedor)->exists();
        $pedido = $this->consultaPedidos($idEmpresa, $fecha)->where('Id_Proveedor', $idProveedor)->exists();

        return ['calendario' => $calendario, 'pedido' => $pedido, 'tiene_entrega' => $calendario && $pedido];
    }

    /**
     * Frena con un 422 si el proveedor no entrega esa fecha, diciendo qué
     * falta: el día en el calendario de horarios, el pedido, o los dos.
     */
    public function exigirEntrega(int $idProveedor, int $idEmpresa, CarbonInterface $fecha, string $campo): void
    {
        $entrega = $this->entregaDelProveedor($idProveedor, $idEmpresa, $fecha);

        if ($entrega['tiene_entrega']) {
            return;
        }

        $faltan = array_keys(array_filter([
            'no tiene ese día ('.self::DIA_POR_INDICE[$fecha->dayOfWeek].') en el calendario de horarios de entrega' => ! $entrega['calendario'],
            'no tiene pedidos con recepción esperada el '.$fecha->format('d/m/Y') => ! $entrega['pedido'],
        ]));

        throw ValidationException::withMessages([
            $campo => ['Este proveedor no entrega ese día: '.implode(' y ', $faltan).'.'],
        ]);
    }

    /**
     * Ids de proveedores de la empresa con la ficha aprobada Y al menos un
     * producto aprobado: los únicos a los que se les califica la recepción
     * (regla 4 del docblock de la clase). Dos consultas agrupadas para toda
     * la empresa, no una por proveedor.
     *
     * Ficha aprobada = la misma regla de
     * Proveedor::estadoGeneralCalificacionFicha: todos los campos
     * calificables tienen calificación y todas son "Aprobado".
     *
     * @return list<int>
     */
    public function idsHabilitadosParaRecepcion(int $idEmpresa): array
    {
        $deLaEmpresa = Proveedor::where('Id_Empresa', $idEmpresa)->select('Id_Proveedor');
        $totalCampos = count(CalificacionProveedorService::CAMPOS_SECCION1) + 2;

        $conFichaAprobada = CalificacionCampoFicha::whereIn('Id_Proveedor', $deLaEmpresa)
            ->groupBy('Id_Proveedor')
            ->havingRaw("COUNT(*) >= ? AND SUM(CASE WHEN Estado = 'Aprobado' THEN 1 ELSE 0 END) = COUNT(*)", [$totalCampos])
            ->pluck('Id_Proveedor')
            ->map(fn ($id) => (int) $id)
            ->all();

        $conProductoAprobado = Producto::whereIn('Id_Proveedor', $deLaEmpresa)
            ->where('Activo', 1)
            ->where('Estado_Calificacion', 'Aprobado')
            ->distinct()
            ->pluck('Id_Proveedor')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_intersect($conFichaAprobada, $conProductoAprobado));
    }

    /**
     * Frena con un 422 si el proveedor no está habilitado para recepciones
     * (ficha aprobada y al menos un producto aprobado), diciendo qué falta.
     */
    public function exigirHabilitado(Proveedor $proveedor, string $campo): void
    {
        $fichaAprobada = $proveedor->fresh('calificacionesCampos')->estadoGeneralCalificacionFicha() === 'Aprobado';
        $productoAprobado = Producto::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->where('Activo', 1)
            ->where('Estado_Calificacion', 'Aprobado')
            ->exists();

        if ($fichaAprobada && $productoAprobado) {
            return;
        }

        $faltan = array_keys(array_filter([
            'su ficha no está aprobada' => ! $fichaAprobada,
            'no tiene ningún producto aprobado' => ! $productoAprobado,
        ]));

        throw ValidationException::withMessages([
            $campo => ['No se califica la recepción de este proveedor: '.implode(' y ', $faltan).'.'],
        ]);
    }

    /** Filas del calendario de horarios activas con ese día de la semana. */
    protected function consultaCalendario(int $idEmpresa, CarbonInterface $fecha)
    {
        return HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->where('Dia_Entrega', self::DIA_POR_INDICE[$fecha->dayOfWeek]);
    }

    /**
     * Pedidos activos con recepción esperada esa fecha. CONVERT explícito:
     * la columna es [date] y el driver sqlsrv es ambiguo con DATEFORMAT dmy
     * (ver App\Models\BaseModel).
     */
    protected function consultaPedidos(int $idEmpresa, CarbonInterface $fecha)
    {
        return PedidoCompra::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->whereRaw('CONVERT(date, Fecha_Recepcion_Esperada) = CONVERT(date, ?)', [$fecha->toDateString()]);
    }

    /**
     * Ids de proveedores que YA tienen su calificación de recepción
     * finalizada en ese año calendario.
     *
     * @return list<int>
     */
    public function idsYaCalificadosEnElAnio(int $idEmpresa, int $anio): array
    {
        return CalificacionRecepcion::where('Id_Empresa', $idEmpresa)
            ->where('Estado', 'Finalizada')
            ->whereRaw('YEAR(Fecha_Recepcion) = ?', [$anio])
            ->distinct()
            ->pluck('Id_Proveedor')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * ¿A este proveedor puntual le toca hoy? Atajo para el detalle de una
     * pantalla, cuando ya se sabe de qué proveedor se habla.
     */
    public function leTocaHoy(int $idProveedor, int $idEmpresa, ?CarbonInterface $fecha = null): bool
    {
        return $this->proveedoresQueTocanHoy($idEmpresa, $fecha)
            ->contains('Id_Proveedor', $idProveedor);
    }

    /**
     * Cuántos proveedores activos de la empresa todavía deben su
     * calificación del año, para poder decir "faltan N antes de diciembre".
     */
    public function pendientesDelAnio(int $idEmpresa, ?int $anio = null): Collection
    {
        $anio ??= (int) now()->year;

        return Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->whereNotIn('Id_Proveedor', $this->idsYaCalificadosEnElAnio($idEmpresa, $anio) ?: [0])
            ->orderBy('Razon_Social')
            ->get();
    }

    /**
     * Día hábil siguiente a una fecha, saltando fines de semana y feriados.
     * Se usa para mensajes del tipo "el próximo día laborable es...".
     */
    public function siguienteDiaLaborable(CarbonInterface $desde): Carbon
    {
        $fecha = $desde->copy()->startOfDay()->addDay();

        // Tope defensivo: 30 iteraciones es más que cualquier racha real de
        // feriados, y evita un bucle infinito si la configuración de
        // feriados quedara mal cargada.
        for ($i = 0; $i < 30 && ! $this->feriados->esLaborable($fecha); $i++) {
            $fecha->addDay();
        }

        return $fecha;
    }
}
