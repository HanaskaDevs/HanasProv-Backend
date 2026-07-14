<?php

namespace App\Modules\Pedidos\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecepcionPedidoDetalle extends BaseModel
{
    protected $table = 'Recepcion_Pedido_Detalle';
    protected $primaryKey = 'Id_Recepcion_Pedido_Detalle';
    public $timestamps = false;

    protected $fillable = [
        'Id_Recepcion_Pedido',
        'Id_Detalle_Pedido_Compra',
        'Cantidad_Recibida',
        'Recepcion_Completa',
        'Observacion',
        'Creado_Por',
        'Fecha_Creacion',
        'Modificado_Por',
        'Fecha_Modificacion',
    ];

    protected $casts = [
        'Cantidad_Recibida' => 'decimal:2',
        'Recepcion_Completa' => 'boolean',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionPedido::class, 'Id_Recepcion_Pedido');
    }

    public function lineaPedido(): BelongsTo
    {
        return $this->belongsTo(DetallePedidoCompra::class, 'Id_Detalle_Pedido_Compra');
    }

    public function imagenes(): HasMany
    {
        return $this->hasMany(RecepcionImagen::class, 'Id_Recepcion_Pedido_Detalle')->where('Activo', true);
    }
}