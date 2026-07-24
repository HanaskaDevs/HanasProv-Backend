<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditoriaRespuesta extends BaseModel
{
    protected $table = 'Auditoria_Respuesta';
    protected $primaryKey = 'Id_Auditoria_Respuesta';
    public $timestamps = false;

    protected $fillable = ['Id_Auditoria', 'Id_Auditoria_Pregunta', 'Puntaje_Obtenido', 'No_Aplica', 'Observacion', 'Fecha_Modificacion'];
    protected $casts = [
        'Puntaje_Obtenido' => 'decimal:2',
        'No_Aplica' => 'boolean',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(AuditoriaPregunta::class, 'Id_Auditoria_Pregunta');
    }

    public function auditoria(): BelongsTo
    {
        return $this->belongsTo(Auditoria::class, 'Id_Auditoria');
    }
}