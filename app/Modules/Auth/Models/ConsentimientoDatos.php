<?php

namespace App\Modules\Auth\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentimientoDatos extends BaseModel
{
    protected $table = 'Consentimiento_Datos';
    protected $primaryKey = 'Id_Consentimiento';
    public $timestamps = false;

    protected $fillable = ['Id_Usuario', 'Version_Politica', 'Ip_Origen', 'Fecha_Aceptacion'];

    protected $casts = ['Fecha_Aceptacion' => 'datetime'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'Id_Usuario');
    }
}