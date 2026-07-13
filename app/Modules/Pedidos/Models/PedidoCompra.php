<?php

namespace App\Modules\Pedidos\Models;

use App\Models\Empresa;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoCompra extends Model
{
    protected $table = 'Pedido_Compra';
    protected $primaryKey = 'Id_Pedido_Compra';
    public $timestamps = false;

    protected $fillable = [
        'Id_Empresa',
        'Id_Proveedor',
        'Nro_Pedido',
        'Fecha_Registro_BC',
        'Fecha_Recepcion_Esperada',
        'Estado_Pedido_BC',
        'Estado',
        'Fecha_Sincronizacion',
        'Cerrado_Por',
        'Fecha_Cierre',
        'Activo',
    ];

    protected $casts = [
        'Fecha_Registro_BC' => 'date',
        'Fecha_Recepcion_Esperada' => 'date',
        'Fecha_Sincronizacion' => 'datetime',
        'Fecha_Cierre' => 'datetime',
        'Activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'Id_Empresa');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(DetallePedidoCompra::class, 'Id_Pedido_Compra');
    }
}
