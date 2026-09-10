<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La cuenta donde se le paga al proveedor. La declara el proveedor junto
 * con el PDF del certificado bancario (boton "Registre sus datos" en
 * Documentacion) -> el PDF sigue siendo el respaldo, esto son los datos
 * estructurados que se postean a la Ficha de Bancos de BC.
 */
class ProveedorCuentaBancaria extends Model
{
    protected $table = 'Proveedor_Cuenta_Bancaria';
    protected $primaryKey = 'Id_Proveedor_Cuenta_Bancaria';
    public $timestamps = false;

    public const TIPOS_CUENTA = ['AHO', 'CTE'];

    protected $fillable = [
        'Id_Proveedor', 'Id_Banco', 'Tipo_Cuenta', 'Nro_Cuenta',
        'Registrado_Por', 'Fecha_Creacion', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class, 'Id_Banco');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'Id_Proveedor');
    }
}
