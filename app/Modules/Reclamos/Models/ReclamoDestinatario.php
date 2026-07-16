<?php

namespace App\Modules\Reclamos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReclamoDestinatario extends Model
{
    protected $table = 'Reclamo_Destinatario';
    protected $primaryKey = 'Id_Reclamo_Destinatario';
    public $timestamps = false;

    protected $fillable = ['Id_Reclamo', 'Rol_Contacto', 'Nombre_Contacto', 'Email'];

    public function reclamo(): BelongsTo
    {
        return $this->belongsTo(Reclamo::class, 'Id_Reclamo');
    }
}
