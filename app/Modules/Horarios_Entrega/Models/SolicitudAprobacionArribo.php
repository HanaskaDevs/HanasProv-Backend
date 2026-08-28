<?php

namespace App\Modules\Horarios_Entrega\Models;

use App\Models\BaseModel;
use App\Modules\Auth\Models\Usuario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un horario cayó a Rechazado (pasaron los
 * config('portal.minutos_atrasado_a_rechazado') minutos sin que el Guardia
 * marque arribo) y el Guardia pidió que igual se registre, porque el
 * proveedor sí llegó -> queda pendiente hasta que Calidad (Valeria) la
 * apruebe o rechace desde el portal. Ver HorarioEntregaService::
 * solicitarAprobacion/aprobarSolicitud/rechazarSolicitud.
 */
class SolicitudAprobacionArribo extends BaseModel
{
    protected $table = 'Solicitud_Aprobacion_Arribo';
    protected $primaryKey = 'Id_Solicitud_Aprobacion_Arribo';
    public $timestamps = false;

    protected $fillable = [
        'Id_Horario_Entrega_Proveedor', 'Fecha', 'Solicitado_Por', 'Fecha_Solicitud',
        'Estado', 'Resuelto_Por', 'Fecha_Resolucion', 'Comentario_Resolucion',
    ];

    protected $casts = [
        'Fecha' => 'date',
        'Fecha_Solicitud' => 'datetime',
        'Fecha_Resolucion' => 'datetime',
    ];

    public function horario(): BelongsTo
    {
        return $this->belongsTo(HorarioEntregaProveedor::class, 'Id_Horario_Entrega_Proveedor');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Solicitado_Por');
    }

    public function resolutor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Resuelto_Por');
    }
}
