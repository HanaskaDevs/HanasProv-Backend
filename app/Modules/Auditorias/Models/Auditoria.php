<?php

namespace App\Modules\Auditorias\Models;

use App\Models\BaseModel;
use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Auditoria extends BaseModel
{
    protected $table = 'Auditoria';
    protected $primaryKey = 'Id_Auditoria';
    public $timestamps = false;

    protected $fillable = [
        'Id_Empresa', 'Id_Tipo_Auditoria', 'Id_Proveedor', 'Id_Usuario_Auditor',
        'Fecha_Auditoria', 'Estado',
        'Puntaje_Total_Posible', 'Puntaje_No_Aplica', 'Puntaje_Obtenido', 'Porcentaje_Cumplimiento',
        'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Fecha_Auditoria' => 'date',
        // decimal:2 y no 'float' a propósito: el driver de SQL Server
        // devuelve decimal como string, y compararlo o sumarlo sin castear
        // arrastra los mismos errores silenciosos que ya documenta el
        // modelo Proveedor con Id_Estado_Proveedor.
        'Puntaje_Total_Posible' => 'decimal:2',
        'Puntaje_No_Aplica' => 'decimal:2',
        'Puntaje_Obtenido' => 'decimal:2',
        'Porcentaje_Cumplimiento' => 'decimal:2',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'Id_Empresa');
    }

    public function tipoAuditoria(): BelongsTo
    {
        return $this->belongsTo(TipoAuditoria::class, 'Id_Tipo_Auditoria');
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
        return $this->hasMany(AuditoriaRespuesta::class, 'Id_Auditoria');
    }
}