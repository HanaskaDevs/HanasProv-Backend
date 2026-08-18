<?php

namespace App\Modules\Auditorias\Services;

use App\Modules\Proveedores\Models\Proveedor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reparte a los proveedores a lo largo del año para su auditoría de
 * recepción, y responde "¿a quién le toca hoy?".
 *
 * La fecha NO se guarda en ninguna tabla: se DERIVA del Id_Proveedor. Eso
 * es a propósito:
 *
 *  - Es estable. Si en cambio repartiéramos "equitativamente" entre los
 *    proveedores activos, dar de alta un proveedor nuevo recalcularía la
 *    fecha de TODOS los demás, y a alguien que ya tenía su auditoría
 *    agendada para marzo se le movería sin que nadie la haya tocado.
 *  - No hay nada que mantener sincronizado: no existe el caso de "la fila
 *    de agenda quedó vieja / se borró / apunta a un proveedor inactivo".
 *
 * El multiplicador 7919 es un primo grande: al ser los Id_Proveedor
 * consecutivos, multiplicar por un primo y tomar módulo 365 hace que dos
 * proveedores creados uno detrás del otro caigan en épocas del año bien
 * separadas (saltos de ~254 días), en vez de amontonarse todos en enero.
 *
 * Frecuencia: el sistema agenda UNA auditoría al año por proveedor. La
 * segunda (el "máximo 2 veces por año" del pedido) queda a criterio de
 * Calidad, que puede iniciar una cuando quiera desde la pantalla; el tope
 * de 2 por año lo hace cumplir CalificacionRecepcionService.
 */
class AgendaRecepcionService
{
    /** Primo grande -> separa en el calendario a proveedores con Id vecino. */
    private const MULTIPLICADOR = 7919;

    /**
     * Día del año (1..365) que le corresponde a este proveedor, siempre el
     * mismo mientras no cambie su Id.
     */
    public function diaDelAnio(int $idProveedor): int
    {
        return (($idProveedor * self::MULTIPLICADOR) % 365) + 1;
    }

    /**
     * Fecha concreta de la auditoría de este proveedor para un año dado.
     * Si cae sábado o domingo se corre al lunes siguiente -> no tiene
     * sentido avisarle a Calidad de una recepción un día que no se recibe.
     */
    public function fechaProgramada(int $idProveedor, ?int $anio = null): Carbon
    {
        $anio ??= (int) now()->year;

        // startOfDay() no es adorno: Carbon::createFromDate() deja la HORA
        // ACTUAL en el objeto, así que dos llamadas seguidas devolvían el
        // mismo día pero instantes distintos, y cualquier comparación que no
        // sea isSameDay() (equalTo, lt, gt) daba resultados raros.
        $fecha = Carbon::createFromDate($anio, 1, 1)
            ->startOfDay()
            ->addDays($this->diaDelAnio($idProveedor) - 1);

        return match ($fecha->dayOfWeek) {
            Carbon::SATURDAY => $fecha->addDays(2),
            Carbon::SUNDAY => $fecha->addDay(),
            default => $fecha,
        };
    }

    public function leTocaHoy(int $idProveedor): bool
    {
        return $this->fechaProgramada($idProveedor)->isSameDay(now());
    }

    /**
     * Proveedores activos de una empresa a los que les toca auditoría hoy.
     * Lo usa el comando que avisa a Calidad cada mañana.
     *
     * @return Collection<int, Proveedor>
     */
    public function proveedoresQueTocanHoy(int $idEmpresa): Collection
    {
        return Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->get()
            ->filter(fn (Proveedor $p) => $this->leTocaHoy($p->Id_Proveedor))
            ->values();
    }
}
