<?php

namespace App\Modules\Auth\Models;

use App\Models\BaseModel;

/**
 * IPs bloqueadas por fuerza bruta (ver AuthService::login).
 *
 * El bloqueo caduca solo a los portal.bloqueo_ip.minutos (antes era
 * permanente: ver config/portal.php). Sistemas puede levantarlo antes desde
 * el portal. Al liberarla, la fila no se borra -> se marca Activa = false y
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
        if (! $ip || static::esExcluida($ip)) {
            return false;
        }

        // Un bloqueo vencido deja de contar aunque siga Activa = true: no hace
        // falta un proceso que lo apague, y la fila queda igual de histórico.
        $desde = now()->subMinutes((int) config('portal.bloqueo_ip.minutos'));

        return static::where('Ip', $ip)
            ->where('Activa', true)
            ->where('Fecha_Bloqueo', '>', $desde->format('Y-m-d\TH:i:s'))
            ->exists();
    }

    /** IPs que nunca se bloquean (hoy, el proxy de redes: ver config/portal.php). */
    public static function esExcluida(string $ip): bool
    {
        return in_array($ip, (array) config('portal.bloqueo_ip.excluidas'), true);
    }
}
