<?php

namespace App\Modules\Pedidos\Models;

use App\Modules\Auth\Models\Usuario;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecepcionPedido extends BaseModel
{
    protected $table = 'Recepcion_Pedido';
    protected $primaryKey = 'Id_Recepcion_Pedido';
    public $timestamps = false;

    protected $fillable = [
        'Id_Pedido_Compra',
        'Fecha_Recepcion',
        'Registrado_Por',
        'Fecha_Creacion',
        'Modificado_Por',
        'Fecha_Modificacion',
    ];

    protected $casts = [
        'Fecha_Recepcion' => 'date',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoCompra::class, 'Id_Pedido_Compra');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Registrado_Por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(RecepcionPedidoDetalle::class, 'Id_Recepcion_Pedido');
    }
}