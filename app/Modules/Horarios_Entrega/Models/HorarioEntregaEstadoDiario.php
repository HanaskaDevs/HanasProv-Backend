<?php

namespace App\Modules\Horarios_Entrega\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorarioEntregaEstadoDiario extends BaseModel
{
    protected $table = 'Horario_Entrega_Estado_Diario';
    protected $primaryKey = 'Id_Horario_Entrega_Estado_Diario';
    public $timestamps = false;

    protected $fillable = [
        'Id_Horario_Entrega_Proveedor', 'Fecha',
        'Hora_Arribo_Real', 'Marcado_Arribo_Por',
        'Hora_Entregado_Real', 'Marcado_Entregado_Por',
        'Fecha_Creacion', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Fecha' => 'date',
        'Hora_Arribo_Real' => 'datetime',
        'Hora_Entregado_Real' => 'datetime',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function horario(): BelongsTo
    {
        return $this->belongsTo(HorarioEntregaProveedor::class, 'Id_Horario_Entrega_Proveedor');
    }
}
