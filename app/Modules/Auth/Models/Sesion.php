<?php

namespace App\Modules\Auth\Models;

use App\Models\Empresa;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sesion extends BaseModel
{
    protected $table = 'Sesion';
    protected $primaryKey = 'Id_Sesion';
    public $timestamps = false;

    // SOLUCIÓN: Evita el error "nvarchar a datetime fuera de intervalo" en Sesiones
    protected $dateFormat = 'Ymd H:i:s'; 

    protected $fillable = [
        'Id_Usuario', 'Token', 'Ip_Origen', 'Dispositivo',
        'Fecha_Inicio', 'Fecha_Expiracion', 'Activa', 'Id_Empresa_Activa',
    ];

    protected $casts = [
        'Activa' => 'boolean',
        'Fecha_Inicio' => 'datetime',
        'Fecha_Expiracion' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario');
    }

    public function empresaActiva(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'Id_Empresa_Activa');
    }
}