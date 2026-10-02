<?php

namespace App\Modules\Responsables\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La persona de Hanaska a la que le escribe un proveedor cuando tiene una
 * duda. Son pocos y se asignan a muchos proveedores (ver
 * ResponsableProveedor).
 */
class Responsable extends BaseModel
{
    protected $table = 'Responsable';

    protected $primaryKey = 'Id_Responsable';

    public $timestamps = false;

    protected $fillable = [
        'Nombre',
        'Correo',
        'Telefono',
        'Activo',
        'Creado_Por',
        'Fecha_Creacion',
        'Modificado_Por',
        'Fecha_Modificacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function asignaciones(): HasMany
    {
        return $this->hasMany(ResponsableProveedor::class, 'Id_Responsable', 'Id_Responsable');
    }
}
