<?php

namespace App\Modules\Responsables\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "El proveedor con este código de BC, en esta empresa, es de esta
 * persona."
 *
 * Se guarda contra el código de BC y no contra un Id_Proveedor del portal
 * a propósito: el archivo que carga Compras trae proveedores que todavía
 * no abrieron su cuenta acá.
 */
class ResponsableProveedor extends BaseModel
{
    protected $table = 'Responsable_Proveedor';

    protected $primaryKey = 'Id_Responsable_Proveedor';

    public $timestamps = false;

    protected $fillable = [
        'Id_Empresa',
        'Nro_Proveedor_BC',
        'Id_Responsable',
        'Creado_Por',
        'Fecha_Creacion',
        'Modificado_Por',
        'Fecha_Modificacion',
    ];

    protected $casts = [
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Responsable::class, 'Id_Responsable', 'Id_Responsable');
    }
}
