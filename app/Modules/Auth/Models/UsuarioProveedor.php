<?php

namespace App\Modules\Auth\Models;

use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class UsuarioProveedor extends Pivot
{
    protected $table = 'Usuario_Proveedor';
    protected $primaryKey = 'Id_Usuario_Proveedor';
    public $incrementing = true;
    public $timestamps = false;

    // No puede extender App\Models\BaseModel (ya extiende Pivot), así que
    // se fuerza acá el mismo formato de fecha seguro. Ver BaseModel para
    // la explicación completa del porqué.
    protected $dateFormat = 'Y-m-d\TH:i:s';

    protected $fillable = [
        'Id_Usuario', 'Id_Proveedor', 'Activo', 'Creado_Por', 'Fecha_Creacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Creacion' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }
}