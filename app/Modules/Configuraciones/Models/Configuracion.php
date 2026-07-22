<?php

namespace App\Modules\Configuraciones\Models;

use App\Models\BaseModel;

class Configuracion extends BaseModel
{
    protected $table = 'Configuracion';
    protected $primaryKey = 'Clave';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['Clave', 'Valor', 'Modificado_Por', 'Fecha_Modificacion'];

    protected $casts = ['Fecha_Modificacion' => 'datetime'];

    public static function obtener(string $clave, ?string $default = null): ?string
    {
        return static::find($clave)?->Valor ?? $default;
    }

    public static function establecer(string $clave, string $valor, int $idUsuario): void
    {
        static::updateOrCreate(
            ['Clave' => $clave],
            ['Valor' => $valor, 'Modificado_Por' => $idUsuario, 'Fecha_Modificacion' => now()]
        );
    }
}