<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** Fotos de la observación (hasta 2, ver ImagenObservacionService). */
    public function imagenes(): HasMany
    {
        return $this->hasMany(AuditoriaRespuestaImagen::class, 'Id_Auditoria_Respuesta')
            ->where('Activo', true);
    }
}