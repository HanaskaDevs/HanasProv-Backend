<?php

namespace App\Modules\Proveedores\Models;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'Proveedor';
    protected $primaryKey = 'Id_Proveedor';
    public $timestamps = false;

    protected $fillable = [
        'Id_Empresa', 'Id_Estado_Proveedor', 'Ruc', 'Razon_Social', 'Nombre_Comercial',
        'Email', 'Telefono', 'Direccion', 'Latitud', 'Longitud', 'Seccion_Actual',
        'Porcentaje_Completado_Ficha', 'Fecha_Postulacion', 'Fecha_Aprobacion',
        'Activo', 'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Latitud' => 'decimal:7',
        'Longitud' => 'decimal:7',
        'Fecha_Postulacion' => 'datetime',
        'Fecha_Aprobacion' => 'datetime',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'Id_Empresa');
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(EstadoProveedor::class, 'Id_Estado_Proveedor');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'Id_Proveedor');
    }

    public function clases(): HasMany
    {
        return $this->hasMany(ProveedorClase::class, 'Id_Proveedor');
    }

    public function categoriasProducto(): HasMany
    {
        return $this->hasMany(ProveedorCategoriaProducto::class, 'Id_Proveedor');
    }

    public function certificaciones(): HasMany
    {
        return $this->hasMany(ProveedorCertificacion::class, 'Id_Proveedor');
    }

    public function historialEstados(): HasMany
    {
        return $this->hasMany(HistorialEstadoProveedor::class, 'Id_Proveedor');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(Archivo::class, 'Id_Proveedor');
    }

    public function aceptacionesNormativa(): HasMany
    {
        return $this->hasMany(AceptacionNormativa::class, 'Id_Proveedor');
    }
}
