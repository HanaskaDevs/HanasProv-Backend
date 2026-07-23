<?php

namespace App\Models;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends BaseModel
{
    protected $table = 'Rol';
    protected $primaryKey = 'Id_Rol';
    public $timestamps = false;

    protected $fillable = ['Nombre_Rol', 'Descripcion', 'Activo', 'Creado_Por', 'Fecha_Creacion'];

    protected $casts = [
        'Activo' => 'boolean',
        'Fecha_Creacion' => 'datetime',
    ];

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'Usuario_Empresa', 'Id_Rol', 'Id_Usuario')
            ->withPivot(['Id_Empresa', 'Activo', 'Id_Usuario_Empresa'])
            ->using(UsuarioEmpresa::class);
    }

    public function usuarioEmpresas(): HasMany
    {
        return $this->hasMany(UsuarioEmpresa::class, 'Id_Rol');
    }
}