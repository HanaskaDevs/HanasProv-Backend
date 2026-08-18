<?php

namespace App\Modules\Auditorias\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uno de los 13 parámetros del formulario FGH04.15.05-1 ("Evaluación de
 * Calidad del Proveedor"). Catálogo fijo, sin CRUD por interfaz: se
 * versiona en RecepcionParametroSeeder, igual que Tipo_Auditoria.
 *
 * Extiende Model y no BaseModel a propósito: no tiene ninguna columna de
 * fecha, así que el $dateFormat de BaseModel no aporta nada (mismo criterio
 * que ClaseProveedor, TipoDocumento y UnidadPresentacion).
 */
class RecepcionParametro extends Model
{
    protected $table = 'Recepcion_Parametro';
    protected $primaryKey = 'Id_Recepcion_Parametro';
    public $timestamps = false;

    protected $fillable = [
        'Orden', 'Descripcion', 'Puntaje',
        'Etiqueta_Afirmativa', 'Etiqueta_Negativa', 'Activo',
    ];

    protected $casts = [
        'Orden' => 'integer',
        'Puntaje' => 'decimal:2',
        'Activo' => 'boolean',
    ];
}
