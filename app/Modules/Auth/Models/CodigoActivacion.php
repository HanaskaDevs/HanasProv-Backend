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
        'Estado_Envio', 'Detalle_Envio', 'Fecha_Envio',
    ];

    /** Ver 2026_10_12_090000_estado_de_envio_del_codigo. */
    public const ENVIO_ENVIANDO = 'Enviando';

    public const ENVIO_ENVIADO = 'Enviado';

    public const ENVIO_FALLIDO = 'Fallido';

    protected $casts = [
        'Usado' => 'boolean',
        'Fecha_Expiracion' => 'datetime',
        'Fecha_Uso' => 'datetime',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Envio' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }
}