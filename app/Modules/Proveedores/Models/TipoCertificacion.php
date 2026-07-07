<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;

class TipoCertificacion extends Model
{
    protected $table = 'Tipo_Certificacion';
    protected $primaryKey = 'Id_Tipo_Certificacion';
    public $timestamps = false;

    protected $fillable = ['Nombre_Certificacion', 'Obligatoria', 'Vigencia_Meses', 'Activo'];

    protected $casts = [
        'Obligatoria' => 'boolean',
        'Activo' => 'boolean',
    ];
}
