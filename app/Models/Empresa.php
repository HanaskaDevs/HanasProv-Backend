<?php

namespace App\Models;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioEmpresa;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Model
{
    protected $table = 'Empresa';
    protected $primaryKey = 'Id_Empresa';
    public $timestamps = false;

    protected $fillable = [
        'Razon_Social','Empresa_BC', 'Ruc', 'Nombre_Comercial', 'Logo_Url', 'Activo',
        'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'Usuario_Empresa', 'Id_Empresa', 'Id_Usuario')
            ->withPivot(['Id_Rol', 'Activo', 'Id_Usuario_Empresa'])
            ->using(UsuarioEmpresa::class);
    }

    public function proveedores(): HasMany
    {
        return $this->hasMany(Proveedor::class, 'Id_Empresa');
    }
}
