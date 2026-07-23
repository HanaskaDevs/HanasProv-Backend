<?php

namespace App\Modules\Ficha_Productos\Models;

use Illuminate\Database\Eloquent\Model;

class UnidadPresentacion extends Model
{
    protected $table = 'Unidad_Presentacion';
    protected $primaryKey = 'Id_Unidad_Presentacion';
    public $timestamps = false;

    protected $fillable = ['Nombre_Unidad', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];
}