<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProveedorClase extends Model
{
    protected $table = 'Proveedor_Clase';
    protected $primaryKey = 'Id_Proveedor_Clase';
    public $timestamps = false;

    protected $fillable = ['Id_Proveedor', 'Id_Clase_Proveedor', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }

    public function clase(): BelongsTo
    {
        return $this->belongsTo(ClaseProveedor::class, 'Id_Clase_Proveedor');
    }
}
