<?php

namespace App\Modules\Configuraciones\Models;

use App\Models\BaseModel;

class BotRegla extends BaseModel
{
    protected $table = 'Bot_Regla';
    protected $primaryKey = 'Id_Bot_Regla';
    public $timestamps = false;

    protected $fillable = [
        'Tipo', 'Palabra_Clave', 'Contenido', 'Orden', 'Activo', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Modificacion' => 'datetime',
    ];
}