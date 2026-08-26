<?php

namespace App\Modules\Asistente\Models;

use App\Models\BaseModel;
use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsistenteConsumo extends BaseModel
{
    protected $table = 'Asistente_Consumo';
    protected $primaryKey = 'Id_Asistente_Consumo';
    public $timestamps = false;

    protected $fillable = [
        'Id_Usuario', 'Id_Empresa', 'Modelo',
        'Tokens_Entrada', 'Tokens_Salida',
        'Tokens_Cache_Escritura', 'Tokens_Cache_Lectura',
        'Costo_Usd', 'Origen', 'Fecha_Creacion',
    ];

    protected $casts = [
        'Tokens_Entrada' => 'integer',
        'Tokens_Salida' => 'integer',
        'Tokens_Cache_Escritura' => 'integer',
        'Tokens_Cache_Lectura' => 'integer',
        // decimal:6 y no 'float': el driver de SQL Server devuelve decimal
        // como string, y estos valores son fracciones de centavo -> sumarlos
        // como string da 0 en silencio.
        'Costo_Usd' => 'decimal:6',
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
