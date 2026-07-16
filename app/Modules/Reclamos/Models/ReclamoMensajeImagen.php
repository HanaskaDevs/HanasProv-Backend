<?php

namespace App\Modules\Reclamos\Models;

use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReclamoMensajeImagen extends BaseModel
{
    protected $table = 'Reclamo_Mensaje_Imagen';
    protected $primaryKey = 'Id_Reclamo_Mensaje_Imagen';
    public $timestamps = false;

    protected $fillable = ['Id_Reclamo_Mensaje', 'Id_Archivo', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];

    public function mensaje(): BelongsTo
    {
        return $this->belongsTo(ReclamoMensaje::class, 'Id_Reclamo_Mensaje');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(Archivo::class, 'Id_Archivo');
    }
}