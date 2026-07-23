<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoProveedor extends Model
{
    protected $table = 'Estado_Proveedor';
    protected $primaryKey = 'Id_Estado_Proveedor';
    public $timestamps = false;

    protected $fillable = ['Nombre_Estado', 'Descripcion', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];
}
