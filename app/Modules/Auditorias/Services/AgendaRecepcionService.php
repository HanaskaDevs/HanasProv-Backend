<?php

namespace App\Modules\Auditorias\Services;

use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Proveedores\Models\Proveedor;
use App\Shared\FeriadosEcuador;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

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
 *   2. EL PROVEEDOR TIENE ENTREGA HOY. Vale por cualquiera de los dos
 *      caminos: está en el calendario semanal de horarios de entrega con el
 *      día de hoy, o tiene un pedido de compra con fecha de recepción
 *      esperada hoy. Se aceptan los dos porque no todo proveedor está en el
 *      calendario, y no todo pedido de BC trae fecha.
 *   3. TODAVÍA NO TIENE SU CALIFICACIÓN DEL AÑO. Es una por año
 *      CALENDARIO, y el plazo se cierra en NOVIEMBRE: todos los proveedores
 *      tienen que estar calificados antes de diciembre, así que diciembre
 *      queda para cerrar rezagados y ya no se genera aviso nuevo.
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

        $pendientes = array_values(array_diff($idsConEntrega, $yaCalificados));

        if ($pendientes === []) {
            return new Collection();
        }

        return Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->whereIn('Id_Proveedor', $pendientes)
            ->orderBy('Razon_Social')
            ->get();
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
     * Ids de proveedores con entrega prevista esa fecha, por calendario de
     * horarios o por pedido de compra.
     *
     * @return list<int>
     */
    public function idsConEntregaEse(int $idEmpresa, CarbonInterface $fecha): array
    {
        $diaSemana = self::DIA_POR_INDICE[$fecha->dayOfWeek];

        $porCalendario = HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->where('Dia_Entrega', $diaSemana)
            ->distinct()
            ->pluck('Id_Proveedor')
            ->all();

        // CONVERT explícito: la columna es [date] y el driver sqlsrv es
        // ambiguo con DATEFORMAT dmy (ver App\Models\BaseModel).
        $porPedido = PedidoCompra::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->whereRaw('CONVERT(date, Fecha_Recepcion_Esperada) = CONVERT(date, ?)', [$fecha->toDateString()])
            ->distinct()
            ->pluck('Id_Proveedor')
            ->all();

        return array_values(array_unique(array_map('intval', array_merge($porCalendario, $porPedido))));
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
