<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaProducto extends Model
{
    protected $table = 'Categoria_Producto';
    protected $primaryKey = 'Id_Categoria_Producto';
    public $timestamps = false;

    protected $fillable = ['Nombre_Categoria', 'Descripcion', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];
}
