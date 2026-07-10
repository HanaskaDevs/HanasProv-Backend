<?php

namespace App\Modules\Pedidos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetallePedidoCompra extends Model
{
    protected $table = 'Detalle_Pedido_Compra';
    protected $primaryKey = 'Id_Detalle_Pedido_Compra';
    public $timestamps = false;

    protected $fillable = [
        'Id_Pedido_Compra', 'Nro_Linea', 'Codigo_Producto',
        'Descripcion', 'Cantidad', 'Fecha_Recepcion_Esperada',
    ];

    protected $casts = [
        'Cantidad' => 'decimal:4',
        'Fecha_Recepcion_Esperada' => 'date',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoCompra::class, 'Id_Pedido_Compra');
    }
}