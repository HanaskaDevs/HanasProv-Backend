<?php

namespace App\Modules\Documentos_Proveedor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoDocumento extends Model
{
    protected $table = 'Tipo_Documento';
    protected $primaryKey = 'Id_Tipo_Documento';
    public $timestamps = false;

    protected $fillable = [
        'Categoria', 'Nombre_Documento', 'Carpeta_Slug',
        'Obligatorio', 'Permite_Multiples', 'Requiere_Fecha_Caducidad',
        'Requiere_Solo_Quito', 'Activo',
    ];

    protected $casts = [
        'Obligatorio' => 'boolean',
        'Permite_Multiples' => 'boolean',
        'Requiere_Fecha_Caducidad' => 'boolean',
        'Requiere_Solo_Quito' => 'boolean',
        'Activo' => 'boolean',
    ];

    public function documentosProveedor(): HasMany
    {
        return $this->hasMany(DocumentoProveedor::class, 'Id_Tipo_Documento');
    }
}