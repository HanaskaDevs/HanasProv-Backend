<?php

namespace App\Modules\Horarios_Entrega\Models;

use App\Models\BaseModel;

/**
 * Historial de horario por proveedor: una fila por cita (proveedor +
 * clasificación + día). Solo lectura para la aplicación: la escribe
 * HistorialHorarioProveedorService (comando horarios-entrega:historial).
 */
class HistorialHorarioProveedor extends BaseModel
{
    protected $table = 'Historial_Horario_Proveedor';
    protected $primaryKey = 'Id';
    public $timestamps = false;

    protected $guarded = [];
}
