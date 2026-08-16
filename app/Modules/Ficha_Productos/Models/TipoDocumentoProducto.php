<?php

namespace App\Modules\Ficha_Productos\Models;

use Illuminate\Database\Eloquent\Model;

class TipoDocumentoProducto extends Model
{
    protected $table = 'Tipo_Documento_Producto';
    protected $primaryKey = 'Id_Tipo_Documento_Producto';
    public $timestamps = false;

    protected $fillable = [
        'Nombre_Documento', 'Carpeta_Slug', 'Codigo_Archivo', 'Obligatorio',
        'Permite_Multiples', 'Requiere_Fecha_Caducidad', 'Activo',
    ];

    protected $casts = [
        'Obligatorio' => 'boolean',
        'Permite_Multiples' => 'boolean',
        'Requiere_Fecha_Caducidad' => 'boolean',
        'Activo' => 'boolean',
    ];
}