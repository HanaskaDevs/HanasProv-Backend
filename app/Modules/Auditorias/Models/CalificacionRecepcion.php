<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La evaluación de UNA recepción concreta de un proveedor. Arranca en
 * 'Borrador' (se va autoguardando parámetro por parámetro) y al finalizar
 * queda con los puntajes congelados y de solo lectura.
 */
class CalificacionRecepcion extends BaseModel
{
    protected $table = 'Calificacion_Recepcion';
    protected $primaryKey = 'Id_Calificacion_Recepcion';
    public $timestamps = false;

    public const ESTADO_BORRADOR = 'Borrador';
    public const ESTADO_FINALIZADA = 'Finalizada';

    protected $fillable = [
        'Id_Empresa', 'Id_Proveedor', 'Id_Usuario_Auditor',
        'Fecha_Recepcion', 'Contacto', 'Estado',
        'Puntaje_Total_Posible', 'Puntaje_Obtenido', 'Porcentaje_Obtenido',
        'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Fecha_Recepcion' => 'date',
        'Puntaje_Total_Posible' => 'decimal:2',
        'Puntaje_Obtenido' => 'decimal:2',
        'Porcentaje_Obtenido' => 'decimal:2',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'Id_Empresa');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario_Auditor');
    }

    public function respuestas(): HasMany
    {
        return $this->hasMany(CalificacionRecepcionRespuesta::class, 'Id_Calificacion_Recepcion');
    }

    public function estaFinalizada(): bool
    {
        return $this->Estado === self::ESTADO_FINALIZADA;
    }
}
