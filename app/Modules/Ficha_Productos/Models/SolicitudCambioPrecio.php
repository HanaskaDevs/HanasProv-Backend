<?php

namespace App\Modules\Ficha_Productos\Models;

use App\Models\BaseModel;
use App\Modules\Auth\Models\Usuario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudCambioPrecio extends BaseModel
{
    protected $table = 'Solicitud_Cambio_Precio';
    protected $primaryKey = 'Id_Solicitud_Cambio_Precio';
    public $timestamps = false;

    protected $fillable = [
        'Id_Producto', 'Precio_Anterior', 'Precio_Nuevo', 'Estado',
        'Solicitado_Por', 'Fecha_Solicitud',
        'Resuelto_Por', 'Fecha_Resolucion', 'Comentario_Resolucion',
    ];

    protected $casts = [
        'Precio_Anterior' => 'decimal:2',
        'Precio_Nuevo' => 'decimal:2',
        'Fecha_Solicitud' => 'datetime',
        'Fecha_Resolucion' => 'datetime',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'Id_Producto');
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
