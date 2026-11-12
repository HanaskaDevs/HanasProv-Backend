<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto adjunta a la observación de UN parámetro del formulario de
 * calificación de recepciones (FGH04.15.05-1). Gemela de
 * AuditoriaRespuestaImagen; ver esa clase y la migración del 12-nov-2026.
 */
class CalificacionRecepcionRespuestaImagen extends BaseModel
{
    protected $table = 'Calificacion_Recepcion_Respuesta_Imagen';
    protected $primaryKey = 'Id_Calificacion_Recepcion_Respuesta_Imagen';
    public $timestamps = false;

    protected $fillable = ['Id_Calificacion_Recepcion_Respuesta', 'Id_Archivo', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];

    public function respuesta(): BelongsTo
    {
        return $this->belongsTo(CalificacionRecepcionRespuesta::class, 'Id_Calificacion_Recepcion_Respuesta');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(Archivo::class, 'Id_Archivo');
    }
}
