<?php

namespace App\Modules\Proveedores\Models;

use App\Modules\Auth\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AceptacionNormativa extends Model
{
    protected $table = 'Aceptacion_Normativa';
    protected $primaryKey = 'Id_Aceptacion_Normativa';
    public $timestamps = false;

    protected $fillable = [
        'Id_Proveedor', 'Id_Usuario', 'Cargo_Firmante', 'Codigo_Documento',
        'Version_Documento', 'Ip_Origen', 'Fecha_Aceptacion',
    ];

    protected $casts = ['Fecha_Aceptacion' => 'datetime'];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario');
    }
}
