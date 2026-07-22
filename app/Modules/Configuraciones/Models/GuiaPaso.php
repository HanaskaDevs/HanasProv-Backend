<?php

namespace App\Modules\Configuraciones\Models;

use App\Models\BaseModel;

class GuiaPaso extends BaseModel
{
    protected $table = 'Guia_Paso';
    protected $primaryKey = 'Id_Guia_Paso';
    public $timestamps = false;

    protected $fillable = ['Orden', 'Target_Id', 'Titulo', 'Texto', 'Activo', 'Modificado_Por', 'Fecha_Modificacion'];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Modificacion' => 'datetime',
    ];
}