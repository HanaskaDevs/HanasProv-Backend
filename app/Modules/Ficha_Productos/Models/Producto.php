<?php

namespace App\Modules\Ficha_Productos\Models;

use App\Modules\Proveedores\Models\Proveedor;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends BaseModel
{
    /**
     * Etapas del circuito de aprobación (ver la migración
     * 2026_09_23_090000). Estado_Calificacion sigue siendo el VEREDICTO;
     * esto dice en qué escritorio está parado el producto.
     */
    public const ETAPA_COMPRAS = 'Compras';
    public const ETAPA_CALIDAD = 'Calidad';

    protected $table = 'Producto';
    protected $primaryKey = 'Id_Producto';
    public $timestamps = false;

    protected $fillable = [
        'Id_Proveedor', 'Id_Unidad_Presentacion', 'Nombre_Producto',
        'Codigo_Barras', 'Precio', 'Peso', 'Volumen', 'Volumen_Masterpack', 'Unidad_Por_Caja', 'Activo',
        'Contenido_Paquete',
        'Masterpack_Largo_Cm', 'Masterpack_Ancho_Cm', 'Masterpack_Alto_Cm',
        'Unidad_Largo_Cm', 'Unidad_Ancho_Cm', 'Unidad_Alto_Cm',
        'Bloqueado', 'Estado_Calificacion', 'Etapa_Aprobacion', 'Comentario_Calificacion',
        'Calificado_Por', 'Fecha_Calificacion',
        'Precio_En_Revision',
        'Bc_Nro_Producto',
        'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Precio' => 'decimal:2',
        'Peso' => 'decimal:3',
        // 6 decimales: el volumen es en m³ y sale de medidas en cm, así
        // que una caja chica da valores como 0,000150 (ver la migración
        // 2026_09_12_090000).
        'Volumen' => 'decimal:6',
        'Volumen_Masterpack' => 'decimal:6',
        'Unidad_Por_Caja' => 'integer',
        'Contenido_Paquete' => 'integer',
        'Masterpack_Largo_Cm' => 'decimal:2',
        'Masterpack_Ancho_Cm' => 'decimal:2',
        'Masterpack_Alto_Cm' => 'decimal:2',
        'Unidad_Largo_Cm' => 'decimal:2',
        'Unidad_Ancho_Cm' => 'decimal:2',
        'Unidad_Alto_Cm' => 'decimal:2',
        'Activo' => 'boolean',
        'Bloqueado' => 'boolean',
        'Precio_En_Revision' => 'boolean',
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

    public function solicitudesCambioPrecio(): HasMany
    {
        return $this->hasMany(SolicitudCambioPrecio::class, 'Id_Producto');
    }

    /**
     * Grupos de producto (EK, CD, PH, IM...) de este producto. Es opcional
     * y múltiple: un producto puede no tener ninguno o tenerlos todos.
     *
     * Sin withPivot ni using: la tabla puente no guarda nada más que las
     * dos claves, así que no hay nada que exponer.
     */
    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(GrupoProducto::class, 'Producto_Grupo', 'Id_Producto', 'Id_Grupo_Producto')
            ->orderBy('Grupo_Producto.Orden')
            ->orderBy('Grupo_Producto.Codigo');
    }
}