<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Grupos de impuesto de Business Central = la "Clase de contribuyente"
 * de la ficha. El proveedor VE la Descripcion ("Persona Natural") pero
 * lo que se guarda en Proveedor.Clase_Contribuyente y lo que viaja a BC
 * es el Codigo ("PERSONA NATURAL").
 */
class GrupoImpuestoBC extends Model
{
    protected $table = 'Grupo_Impuesto_BC';
    protected $primaryKey = 'Id_Grupo_Impuesto_BC';
    public $timestamps = false;

    protected $fillable = ['Codigo', 'Descripcion', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];
}
