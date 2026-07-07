<?php

namespace App\Modules\Auth\Models;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sesion extends Model
{
    protected $table = 'Sesion';
    protected $primaryKey = 'Id_Sesion';
    public $timestamps = false;

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
