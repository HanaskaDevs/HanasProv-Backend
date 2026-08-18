<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalificacionRecepcionRespuesta extends BaseModel
{
    protected $table = 'Calificacion_Recepcion_Respuesta';
    protected $primaryKey = 'Id_Calificacion_Recepcion_Respuesta';
    public $timestamps = false;

    protected $fillable = [
        'Id_Calificacion_Recepcion', 'Id_Recepcion_Parametro',
        'Cumple', 'Puntaje_Obtenido', 'Observacion', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Cumple' => 'boolean',
        'Puntaje_Obtenido' => 'decimal:2',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function calificacion(): BelongsTo
    {
        return $this->belongsTo(CalificacionRecepcion::class, 'Id_Calificacion_Recepcion');
    }

    public function parametro(): BelongsTo
    {
        return $this->belongsTo(RecepcionParametro::class, 'Id_Recepcion_Parametro');
    }
}
