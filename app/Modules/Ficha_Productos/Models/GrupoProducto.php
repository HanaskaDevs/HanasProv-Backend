<?php

namespace App\Modules\Ficha_Productos\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Catálogo de grupos de producto (EK, CD, PH, IM y los que agregue
 * Sistemas). Global, sin empresa: los grupos son los mismos para todo el
 * grupo empresarial, igual que Categoria_Producto y Clase_Proveedor.
 */
class GrupoProducto extends BaseModel
{
    protected $table = 'Grupo_Producto';
    protected $primaryKey = 'Id_Grupo_Producto';
    public $timestamps = false;

    protected $fillable = ['Codigo', 'Nombre', 'Descripcion', 'Orden', 'Activo'];

    protected $casts = [
        'Orden' => 'integer',
        'Activo' => 'boolean',
    ];

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'Producto_Grupo', 'Id_Grupo_Producto', 'Id_Producto');
    }
}
