<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaProducto extends Model
{
    protected $table = 'Categoria_Producto';
    protected $primaryKey = 'Id_Categoria_Producto';
    public $timestamps = false;

    protected $fillable = ['Nombre_Categoria', 'Id_Categoria_Padre', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];

    public function padre(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'Id_Categoria_Padre');
    }

    public function hijas(): HasMany
    {
        return $this->hasMany(CategoriaProducto::class, 'Id_Categoria_Padre');
    }
}
