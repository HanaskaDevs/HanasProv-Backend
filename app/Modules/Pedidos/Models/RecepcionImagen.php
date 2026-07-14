<?php

namespace App\Modules\Pedidos\Models;

use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecepcionImagen extends BaseModel
{
    protected $table = 'Recepcion_Imagen';
    protected $primaryKey = 'Id_Recepcion_Imagen';
    public $timestamps = false;

    protected $fillable = [
        'Id_Recepcion_Pedido_Detalle',
        'Id_Archivo',
        'Activo',
        'Creado_Por',
        'Fecha_Creacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Creacion' => 'datetime',
    ];

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(RecepcionPedidoDetalle::class, 'Id_Recepcion_Pedido_Detalle');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(Archivo::class, 'Id_Archivo');
    }
}