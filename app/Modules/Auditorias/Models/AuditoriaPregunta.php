<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditoriaPregunta extends BaseModel
{
    protected $table = 'Auditoria_Pregunta';
    protected $primaryKey = 'Id_Auditoria_Pregunta';
    public $timestamps = false;

    protected $fillable = ['Id_Auditoria_Seccion', 'Subseccion', 'Numero', 'Descripcion', 'Puntaje_Max', 'Orden', 'Activo'];
    protected $casts = ['Puntaje_Max' => 'decimal:2', 'Activo' => 'boolean'];

    public function seccion(): BelongsTo
    {
        return $this->belongsTo(AuditoriaSeccion::class, 'Id_Auditoria_Seccion');
    }
}
