<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BitacoraAcceso extends Model
{
    protected $table = 'Bitacora_Acceso';
    protected $primaryKey = 'Id_Bitacora';
    public $timestamps = false;

    // SOLUCIÓN: Fuerza el formato universal que SQL Server entiende en CUALQUIER idioma/BD
    protected $dateFormat = 'Ymd H:i:s'; 

    protected $fillable = [
        'Id_Usuario', 'Email_Intento', 'Tipo_Evento',
        'Ip_Origen', 'User_Agent', 'Fecha_Evento',
    ];

    protected $casts = [
        'Fecha_Evento' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario');
    }
}