<?php

namespace App\Modules\Horarios_Entrega\Models;

use App\Models\BaseModel;
use App\Models\Empresa;
use App\Modules\Proveedores\Models\Proveedor;
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
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Tiempo_Preparacion_Min' => 'integer',
        'Tiempo_Permanencia_Min' => 'integer',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'Id_Empresa');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }
}
