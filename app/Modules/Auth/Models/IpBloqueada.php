<?php

namespace App\Modules\Auth\Models;

use App\Models\BaseModel;

/**
 * IPs bloqueadas por fuerza bruta (ver AuthService::login).
 *
 * El bloqueo NO caduca solo: queda hasta que Sistemas lo levante desde el
 * portal. Al liberarla, la fila no se borra -> se marca Activa = false y
 * queda el histórico de que esa IP atacó y quién la liberó.
 */
class IpBloqueada extends BaseModel
{
    protected $table = 'Ip_Bloqueada';
    protected $primaryKey = 'Id_Ip_Bloqueada';
    public $timestamps = false;

    protected $fillable = [
        'Ip', 'Motivo', 'Intentos', 'Fecha_Bloqueo',
        'Desbloqueada_Por', 'Fecha_Desbloqueo', 'Activa',
    ];

    protected $casts = [
        'Activa' => 'boolean',
        'Intentos' => 'integer',
        'Fecha_Bloqueo' => 'datetime',
        'Fecha_Desbloqueo' => 'datetime',
    ];

    public static function estaBloqueada(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }

        return static::where('Ip', $ip)->where('Activa', true)->exists();
    }
}
