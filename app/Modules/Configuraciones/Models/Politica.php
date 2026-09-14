<?php

namespace App\Modules\Configuraciones\Models;

use Illuminate\Database\Eloquent\Model;

class Politica extends Model
{
    protected $table = 'Politica';
    protected $primaryKey = 'Id_Politica';
    public $timestamps = false;

    protected $fillable = [
        'Orden',
        'Titulo',
        'Descripcion',
        'Activo',
        'Modificado_Por',
        'Fecha_Modificacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        
    ];
}