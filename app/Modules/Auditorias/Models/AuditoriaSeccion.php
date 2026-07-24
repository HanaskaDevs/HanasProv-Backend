<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditoriaSeccion extends BaseModel
{
    protected $table = 'Auditoria_Seccion';
    protected $primaryKey = 'Id_Auditoria_Seccion';
    public $timestamps = false;

    protected $fillable = ['Id_Tipo_Auditoria', 'Nombre_Seccion', 'Orden', 'Activo'];
    protected $casts = ['Activo' => 'boolean'];

    public function tipoAuditoria(): BelongsTo
    {
        return $this->belongsTo(TipoAuditoria::class, 'Id_Tipo_Auditoria');
    }

    public function preguntas(): HasMany
    {
        return $this->hasMany(AuditoriaPregunta::class, 'Id_Auditoria_Seccion')->orderBy('Orden');
    }
}