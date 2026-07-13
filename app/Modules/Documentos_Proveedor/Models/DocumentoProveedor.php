<?php

namespace App\Modules\Documentos_Proveedor\Models;

use App\Modules\Proveedores\Models\Proveedor;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoProveedor extends BaseModel
{
    protected $table = 'Documento_Proveedor';
    protected $primaryKey = 'Id_Documento_Proveedor';
    public $timestamps = false;

    protected $fillable = [
        'Id_Proveedor', 'Id_Tipo_Documento', 'Id_Archivo', 'Fecha_Caducidad',
        'Notificacion_Enviada', 'Estado', 'Activo',
        'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Fecha_Caducidad' => 'date',
        'Notificacion_Enviada' => 'boolean',
        'Activo' => 'boolean',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'Id_Tipo_Documento');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(Archivo::class, 'Id_Archivo');
    }
}