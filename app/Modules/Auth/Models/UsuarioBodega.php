<?php

namespace App\Modules\Auth\Models;

use App\Models\BaseModel;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsuarioBodega extends BaseModel
{
    protected $table = 'Usuario_Bodega';
    protected $primaryKey = 'Id_Usuario_Bodega';
    public $timestamps = false;

    protected $fillable = [
        'Id_Usuario', 'Id_Empresa', 'Cod_Almacen', 'Activo',
        'Creado_Por', 'Fecha_Creacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Creacion' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'Id_Empresa');
    }
}