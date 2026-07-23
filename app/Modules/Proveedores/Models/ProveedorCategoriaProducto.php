<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProveedorCategoriaProducto extends Model
{
    protected $table = 'Proveedor_Categoria_Producto';
    protected $primaryKey = 'Id_Proveedor_Categoria';
    public $timestamps = false;

    protected $fillable = ['Id_Proveedor', 'Id_Categoria_Producto', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'Id_Categoria_Producto');
    }
}
