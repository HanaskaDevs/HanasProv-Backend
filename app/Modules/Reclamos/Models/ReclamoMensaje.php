<?php

namespace App\Modules\Reclamos\Models;

use App\Modules\Auth\Models\Usuario;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReclamoMensaje extends BaseModel
{
    protected $table = 'Reclamo_Mensaje';
    protected $primaryKey = 'Id_Reclamo_Mensaje';
    public $timestamps = false;

    protected $fillable = ['Id_Reclamo', 'Id_Usuario_Autor', 'Mensaje', 'Fecha_Creacion'];

    protected $casts = [
        'Fecha_Creacion' => 'datetime',
    ];

    public function reclamo(): BelongsTo
    {
        return $this->belongsTo(Reclamo::class, 'Id_Reclamo');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario_Autor');
    }

    public function imagenes(): HasMany
    {
        return $this->hasMany(ReclamoMensajeImagen::class, 'Id_Reclamo_Mensaje')->where('Activo', true);
    }
}