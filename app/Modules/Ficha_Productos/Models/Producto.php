<?php

namespace App\Modules\Ficha_Productos\Models;

use App\Modules\Proveedores\Models\Proveedor;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends BaseModel
{
    protected $table = 'Producto';
    protected $primaryKey = 'Id_Producto';
    public $timestamps = false;

    protected $fillable = [
        'Id_Proveedor', 'Id_Unidad_Presentacion', 'Nombre_Producto',
        'Codigo_Barras', 'Precio', 'Activo',
        'Bloqueado', 'Estado_Calificacion', 'Comentario_Calificacion',
        'Calificado_Por', 'Fecha_Calificacion',
        'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Precio' => 'decimal:2',
        'Activo' => 'boolean',
        'Bloqueado' => 'boolean',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
        'Fecha_Calificacion' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function unidadPresentacion(): BelongsTo
    {
        return $this->belongsTo(UnidadPresentacion::class, 'Id_Unidad_Presentacion');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoProducto::class, 'Id_Producto');
    }
}