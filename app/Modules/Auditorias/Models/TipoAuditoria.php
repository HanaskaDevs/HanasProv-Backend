<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoAuditoria extends BaseModel
{
    protected $table = 'Tipo_Auditoria';
    protected $primaryKey = 'Id_Tipo_Auditoria';
    public $timestamps = false;

    protected $fillable = ['Nombre', 'Orden', 'Activo'];
    protected $casts = ['Activo' => 'boolean'];

    public function secciones(): HasMany
    {
        return $this->hasMany(AuditoriaSeccion::class, 'Id_Tipo_Auditoria')->orderBy('Orden');
    }

    /**
     * Clases de Proveedor a las que corresponde este Tipo_Auditoria (ej.
     * "Comerciantes" <-> Clase "Comerciante") -> ver
     * AuditoriaService::listarProveedoresParaAuditoria(), que usa esto
     * para marcar/priorizar como "sugerido" a los proveedores que
     * calzan con el tipo de auditoría elegido.
     */
    public function clasesRelacionadas(): HasMany
    {
        return $this->hasMany(TipoAuditoriaClase::class, 'Id_Tipo_Auditoria');
    }
}