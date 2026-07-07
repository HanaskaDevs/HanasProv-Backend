<?php

namespace App\Modules\Proveedores\Models;

use App\Modules\Auth\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Archivo extends Model
{
    protected $table = 'Archivo';
    protected $primaryKey = 'Id_Archivo';
    public $timestamps = false;

    protected $fillable = [
        'Id_Proveedor', 'Nombre_Original', 'Ruta_Almacenamiento', 'Hash_Archivo',
        'Tipo_Mime', 'Tamano_Bytes', 'Categoria_Archivo', 'Id_Usuario_Carga',
        'Fecha_Carga', 'Activo',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Carga' => 'datetime',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function usuarioCarga(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario_Carga');
    }
}
