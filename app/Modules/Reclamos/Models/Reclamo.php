<?php

namespace App\Modules\Reclamos\Models;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Models\BaseModel;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reclamo extends BaseModel
{
    protected $table = 'Reclamo';
    protected $primaryKey = 'Id_Reclamo';
    public $timestamps = false;

    protected $fillable = [
        'Id_Empresa', 'Id_Proveedor', 'Asunto', 'Estado',
        'Creado_Por', 'Fecha_Creacion', 'Cerrado_Por', 'Fecha_Cierre', 'Activo',
    ];

    protected $casts = [
        'Fecha_Creacion' => 'datetime',
        'Fecha_Cierre' => 'datetime',
        'Activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'Id_Empresa');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Creado_Por');
    }

    public function destinatarios(): HasMany
    {
        return $this->hasMany(ReclamoDestinatario::class, 'Id_Reclamo');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(ReclamoMensaje::class, 'Id_Reclamo')->orderBy('Fecha_Creacion');
    }
}