<?php

namespace App\Modules\Proveedores\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de que el proveedor aceptó las Políticas de Hanaska al enviar
 * su ficha a revisión. Una fila por proveedor; ver la migración
 * 2026_09_17_100000_crear_confirmacion_ficha para el porqué de la tabla.
 *
 * Fecha_Confirmacion es DATE (solo fecha). El cast 'date' hace que Eloquent
 * la entregue como Carbon a medianoche y la serialice sin hora.
 */
class ConfirmacionFicha extends BaseModel
{
    protected $table = 'Confirmacion_Ficha';
    protected $primaryKey = 'Id_Confirmacion_Ficha';
    public $timestamps = false;

    protected $fillable = ['Id_Proveedor', 'Fecha_Confirmacion'];

    protected $casts = ['Fecha_Confirmacion' => 'date:Y-m-d'];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }
}
