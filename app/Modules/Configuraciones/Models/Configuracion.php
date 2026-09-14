<?php

namespace App\Modules\Configuraciones\Models;

use App\Models\BaseModel;
use Illuminate\Support\Facades\DB;

class Configuracion extends BaseModel
{
    protected $table = 'Configuracion';
    protected $primaryKey = 'Clave';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['Clave', 'Valor', 'Modificado_Por', 'Fecha_Modificacion'];

    protected $casts = [];

    public static function obtener(string $clave, ?string $default = null): ?string
    {
        return static::find($clave)?->Valor ?? $default;
    }

    public static function establecer(string $clave, string $valor, int $idUsuario): void
    {
        $ahora = DB::raw("CONVERT(datetime, '" . now()->format('Y-m-d H:i:s') . "', 120)");

        static::updateOrCreate(
            ['Clave' => $clave],
            ['Valor' => $valor, 'Modificado_Por' => $idUsuario, 'Fecha_Modificacion' => $ahora]
        );
    }
}