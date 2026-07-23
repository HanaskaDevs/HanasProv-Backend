<?php

namespace App\Modules\Auth\Models;

use App\Modules\Proveedores\Models\Proveedor;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodigoActivacion extends BaseModel
{
    protected $table = 'Codigo_Activacion';
    protected $primaryKey = 'Id_Codigo_Activacion';
    public $timestamps = false;

    protected $fillable = [
        'Email', 'Id_Proveedor', 'Tipo', 'Codigo', 'Fecha_Expiracion',
        'Usado', 'Fecha_Uso', 'Creado_Por', 'Fecha_Creacion',
    ];

    protected $casts = [
        'Usado' => 'boolean',
        'Fecha_Expiracion' => 'datetime',
        'Fecha_Uso' => 'datetime',
        'Fecha_Creacion' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }
}