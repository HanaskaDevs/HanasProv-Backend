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
        'Categoria', 'Nombre_Documento', 'Carpeta_Slug', 'Codigo_Archivo',
        'Obligatorio', 'Permite_Multiples', 'Requiere_Fecha_Caducidad',
        'Requiere_Solo_Quito', 'Requiere_Excepto_Quito', 'Activo',
    ];

    protected $casts = [
        'Obligatorio' => 'boolean',
        'Permite_Multiples' => 'boolean',
        'Requiere_Fecha_Caducidad' => 'boolean',
        'Requiere_Solo_Quito' => 'boolean',
        'Requiere_Excepto_Quito' => 'boolean',
        'Activo' => 'boolean',
    ];

    public function documentosProveedor(): HasMany
    {
        return $this->hasMany(DocumentoProveedor::class, 'Id_Tipo_Documento');
    }

    /**
     * Clases de Proveedor que NO necesitan este tipo de documento (ej.
     * LUAE no se le pide a un Productor Agrícola). Ver
     * DocumentoProveedorService::idsTiposDocumentoExcluidosPorClase().
     */
    public function clasesExcluidas(): HasMany
    {
        return $this->hasMany(TipoDocumentoClaseExcluida::class, 'Id_Tipo_Documento');
    }
}