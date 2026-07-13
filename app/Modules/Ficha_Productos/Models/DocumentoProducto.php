<?php

namespace App\Modules\Ficha_Productos\Models;

use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoProducto extends BaseModel
{
    protected $table = 'Documento_Producto';
    protected $primaryKey = 'Id_Documento_Producto';
    public $timestamps = false;

    protected $fillable = [
        'Id_Producto', 'Id_Tipo_Documento_Producto', 'Id_Archivo', 'Activo',
        'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'Id_Producto');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumentoProducto::class, 'Id_Tipo_Documento_Producto');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(Archivo::class, 'Id_Archivo');
    }
}