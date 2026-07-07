<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProveedorCertificacion extends Model
{
    protected $table = 'Proveedor_Certificacion';
    protected $primaryKey = 'Id_Proveedor_Certificacion';
    public $timestamps = false;

    protected $fillable = [
        'Id_Proveedor', 'Id_Tipo_Certificacion', 'Id_Archivo', 'Numero_Certificado',
        'Fecha_Emision', 'Fecha_Vencimiento', 'Notificacion_Enviada', 'Estado',
        'Activo', 'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Notificacion_Enviada' => 'boolean',
        'Activo' => 'boolean',
        'Fecha_Emision' => 'date',
        'Fecha_Vencimiento' => 'date',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoCertificacion::class, 'Id_Tipo_Certificacion');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(Archivo::class, 'Id_Archivo');
    }
}
