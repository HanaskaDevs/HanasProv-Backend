<?php

namespace App\Modules\Proveedores\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalificacionCampoFicha extends BaseModel
{
    protected $table = 'Calificacion_Campo_Ficha';
    protected $primaryKey = 'Id_Calificacion_Campo';
    public $timestamps = false;

    protected $fillable = [
        'Id_Proveedor', 'Nombre_Campo', 'Estado', 'Comentario', 'Calificado_Por', 'Fecha_Calificacion',
    ];

    protected $casts = [
        'Fecha_Calificacion' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }
}