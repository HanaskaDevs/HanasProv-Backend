<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use App\Modules\Proveedores\Models\ClaseProveedor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TipoAuditoriaClase extends BaseModel
{
    protected $table = 'Tipo_Auditoria_Clase';
    protected $primaryKey = 'Id_Tipo_Auditoria_Clase';
    public $timestamps = false;

    protected $fillable = ['Id_Tipo_Auditoria', 'Id_Clase_Proveedor', 'Activo'];
    protected $casts = ['Activo' => 'boolean'];

    public function tipoAuditoria(): BelongsTo
    {
        return $this->belongsTo(TipoAuditoria::class, 'Id_Tipo_Auditoria');
    }

    public function claseProveedor(): BelongsTo
    {
        return $this->belongsTo(ClaseProveedor::class, 'Id_Clase_Proveedor');
    }
}
