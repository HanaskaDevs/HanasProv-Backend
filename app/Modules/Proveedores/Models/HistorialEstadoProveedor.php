<?php

namespace App\Modules\Proveedores\Models;

use App\Modules\Auth\Models\Usuario;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistorialEstadoProveedor extends BaseModel
{
    protected $table = 'Historial_Estado_Proveedor';
    protected $primaryKey = 'Id_Historial';
    public $timestamps = false;

    protected $fillable = [
        'Id_Proveedor', 'Id_Estado_Anterior', 'Id_Estado_Nuevo',
        'Motivo', 'Id_Usuario', 'Fecha_Cambio',
    ];

    protected $casts = ['Fecha_Cambio' => 'datetime'];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function estadoAnterior(): BelongsTo
    {
        return $this->belongsTo(EstadoProveedor::class, 'Id_Estado_Anterior');
    }

    public function estadoNuevo(): BelongsTo
    {
        return $this->belongsTo(EstadoProveedor::class, 'Id_Estado_Nuevo');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario');
    }
}