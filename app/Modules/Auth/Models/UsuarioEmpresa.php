<?php

namespace App\Modules\Auth\Models;

use App\Models\Empresa;
use App\Models\Rol;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class UsuarioEmpresa extends Pivot
{
    protected $table = 'Usuario_Empresa';
    protected $primaryKey = 'Id_Usuario_Empresa';
    public $incrementing = true;
    public $timestamps = false;

    // No puede extender App\Models\BaseModel (ya extiende Pivot), así que
    // se fuerza acá el mismo formato de fecha seguro. Ver BaseModel para
    // la explicación completa del porqué.
    protected $dateFormat = 'Y-m-d\TH:i:s';

    protected $fillable = [
        'Id_Usuario', 'Id_Empresa', 'Id_Rol', 'Activo',
        'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'Id_Empresa');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'Id_Rol');
    }
}