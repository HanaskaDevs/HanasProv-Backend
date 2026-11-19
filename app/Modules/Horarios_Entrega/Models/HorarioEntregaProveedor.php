<?php

namespace App\Modules\Horarios_Entrega\Models;

use App\Models\BaseModel;
use App\Models\Empresa;
use App\Modules\Proveedores\Models\Proveedor;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorarioEntregaProveedor extends BaseModel
{
    protected $table = 'Horario_Entrega_Proveedor';
    protected $primaryKey = 'Id_Horario_Entrega_Proveedor';
    public $timestamps = false;

    // Los 3 valores válidos de Clasificacion. Definen también las 3
    // pestañas del CRUD (ver pedido del usuario: "en el cual va a ver 3
    // pestañas en el modal, uno por cada clasificación").
    public const PERECIBLES = 'Perecibles';
    public const NO_PERECIBLES = 'No_Perecibles';
    public const FRUVER = 'Fruver';

    public const CLASIFICACIONES = [self::PERECIBLES, self::NO_PERECIBLES, self::FRUVER];

    public const DIAS = [
        'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo',
    ];

    protected $fillable = [
        'Id_Empresa', 'Id_Proveedor', 'Clasificacion', 'Dia_Entrega', 'Anden_Puerta',
        'Hora_Llegada', 'Tiempo_Preparacion_Min', 'Tiempo_Permanencia_Min', 'Hora_Salida',
        'Activo', 'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
        'Fecha_Fuera_Planificacion', 'Id_Turno_Bc',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Tiempo_Preparacion_Min' => 'integer',
        'Tiempo_Permanencia_Min' => 'integer',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
        'Fecha_Fuera_Planificacion' => 'date',
        'Id_Turno_Bc' => 'integer',
    ];

    // Carbon::dayOfWeek: 0 = Domingo .. 6 = Sábado.
    public const DIA_POR_INDICE = [
        0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles',
        4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado',
    ];

    /**
     * Pedido fuera de planificación (19-nov-2026): un turno suelto de una
     * fecha concreta agendado desde Business Central, no un horario del
     * calendario semanal. Ver FueraPlanificacionService.
     */
    public function esFueraDePlanificacion(): bool
    {
        return $this->Fecha_Fuera_Planificacion !== null;
    }

    /**
     * Solo el calendario SEMANAL (la plantilla que se repite cada semana).
     * Lo usan las pantallas y procesos que trabajan con la plantilla: el
     * calendario, la carga masiva y la sincronización con BC (que dan de
     * baja lo que no viene: sin este filtro se llevarían los turnos fuera de
     * planificación), Hana y los avisos que listan el horario semanal.
     */
    public function scopePlanificados(Builder $query): Builder
    {
        return $query->whereNull('Fecha_Fuera_Planificacion');
    }

    /**
     * Las citas de UNA fecha: los horarios semanales de ese día de la semana
     * más los turnos fuera de planificación de esa fecha.
     */
    public function scopeQueTocanEl(Builder $query, CarbonInterface $fecha): Builder
    {
        $dia = self::DIA_POR_INDICE[$fecha->dayOfWeek];

        return $query->where(function (Builder $q) use ($dia, $fecha) {
            $q->where(fn (Builder $semanal) => $semanal->whereNull('Fecha_Fuera_Planificacion')->where('Dia_Entrega', $dia))
                ->orWhere(fn (Builder $turno) => $turno->whereDate('Fecha_Fuera_Planificacion', $fecha->toDateString()));
        });
    }

    /**
     * Cuántos minutos de atraso se le aceptan al Guardia para marcar el
     * arribo directo: 30 en el calendario (después puede pedir aprobación a
     * Calidad), 10 en un turno fuera de planificación (después, nada).
     */
    public function toleranciaMinutos(): int
    {
        return $this->esFueraDePlanificacion()
            ? (int) config('portal.fuera_planificacion.tolerancia_minutos', 10)
            : (int) config('portal.minutos_atrasado_a_rechazado', 30);
    }

    /**
     * ¿Ese atraso (en segundos desde la hora citada) ya pasó la tolerancia?
     *
     * Calendario: igual que siempre, a los 30 min justos ya es Rechazado.
     * Fuera de planificación: "hasta con 10 min de atraso" -> 10:00 todavía
     * se puede marcar; 10:01, no.
     */
    public function excedeTolerancia(float $segundosTarde): bool
    {
        $tolerancia = $this->toleranciaMinutos();

        return $this->esFueraDePlanificacion()
            ? $segundosTarde > $tolerancia * 60
            : $segundosTarde / 60 >= $tolerancia;
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'Id_Empresa');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }
}
