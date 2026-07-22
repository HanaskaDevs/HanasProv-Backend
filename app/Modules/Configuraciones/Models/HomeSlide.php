<?php

namespace App\Modules\Configuraciones\Models;

use App\Models\BaseModel;

class HomeSlide extends BaseModel
{
    protected $table = 'Home_Slide';
    protected $primaryKey = 'Id_Home_Slide';
    public $timestamps = false;

    protected $fillable = [
        'Orden', 'Eyebrow', 'Titulo', 'Descripcion', 'Ruta_Media', 'Tipo_Media',
        'Activo', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Modificacion' => 'datetime',
    ];
}