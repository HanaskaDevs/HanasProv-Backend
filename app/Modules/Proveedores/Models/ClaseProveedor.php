<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;

class ClaseProveedor extends Model
{
    protected $table = 'Clase_Proveedor';
    protected $primaryKey = 'Id_Clase_Proveedor';
    public $timestamps = false;

    protected $fillable = ['Nombre_Clase', 'Icono_Url', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];
}
