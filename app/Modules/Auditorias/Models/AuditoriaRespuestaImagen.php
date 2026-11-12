<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto adjunta a la observación de UNA pregunta de la auditoría.
 *
 * Mismo diseño que Reclamo_Mensaje_Imagen: esta tabla solo enlaza; la
 * ruta, el hash y el tamaño viven en `Archivo`.
 */
class AuditoriaRespuestaImagen extends BaseModel
{
    protected $table = 'Auditoria_Respuesta_Imagen';
    protected $primaryKey = 'Id_Auditoria_Respuesta_Imagen';
    public $timestamps = false;

    protected $fillable = ['Id_Auditoria_Respuesta', 'Id_Archivo', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];

    public function respuesta(): BelongsTo
    {
        return $this->belongsTo(AuditoriaRespuesta::class, 'Id_Auditoria_Respuesta');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(Archivo::class, 'Id_Archivo');
    }
}
