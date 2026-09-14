<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalogo de bancos (222 activos, de la hoja "CODIGO BANCOS" del Excel
 * de BC). Codigo_BC es lo que BC espera en "Cod. sucursal banco" y NUNCA
 * se le muestra al proveedor: el elige por nombre y el codigo viaja solo.
 */
class Banco extends Model
{
    protected $table = 'Banco';
    protected $primaryKey = 'Id_Banco';
    public $timestamps = false;

    protected $fillable = ['Codigo_BC', 'Nombre_Banco', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];
}
