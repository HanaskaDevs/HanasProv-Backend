<?php

namespace App\Modules\Auth\Models;

use App\Models\Empresa;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use Notifiable, HasApiTokens;

    protected $table = 'Usuario';
    protected $primaryKey = 'Id_Usuario';
    public $timestamps = false;

    // SOLUCIÓN: Forza el formato ISO sin guiones que SQL Server acepta en cualquier idioma/BD
    protected $dateFormat = 'Ymd H:i:s'; 

    protected $authPasswordName = 'Password_Hash';

    protected $fillable = [
        'Email', 'Password_Hash', 'Nombre_Completo', 'Cargo',
        'Telefono', 'Requieres_Cambio_Password', 'Ultimo_Acceso', 'Activo',
        'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
        'Tipo_Usuario',
    ];

    protected $hidden = ['Password_Hash'];

    protected $casts = [
        'Activo' => 'boolean',
        'Requiere_Cambio_Password' => 'boolean',
        'Ultimo_Acceso' => 'datetime',
        'Fecha_Creacion' => 'datetime',
        'Fecha_Modificacion' => 'datetime',
    ];

    /**
     * Proveedores vinculados a este usuario externo, vía la tabla puente
     * Usuario_Proveedor. En la práctica un usuario externo suele tener
     * un solo Proveedor activo, pero el esquema permite varios (ej. un
     * mismo proveedor con distintos contactos/usuarios).
     */
    public function proveedores(): BelongsToMany
    {
        return $this->belongsToMany(Proveedor::class, 'Usuario_Proveedor', 'Id_Usuario', 'Id_Proveedor')
            ->withPivot(['Activo', 'Id_Usuario_Proveedor'])
            ->using(UsuarioProveedor::class);
    }

    public function usuarioProveedores(): HasMany
    {
        return $this->hasMany(UsuarioProveedor::class, 'Id_Usuario');
    }

    /**
     * Atajo para el caso normal: el Proveedor principal (activo) de este
     * usuario externo, o null si todavía no tiene ninguno vinculado.
     */
    public function proveedorPrincipal(): ?Proveedor
    {
        return $this->proveedores()->wherePivot('Activo', true)->first();
    }

    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'Usuario_Empresa', 'Id_Usuario', 'Id_Empresa')
            ->withPivot(['Id_Rol', 'Activo', 'Id_Usuario_Empresa'])
            ->using(UsuarioEmpresa::class);
    }

    public function usuarioEmpresas(): HasMany
    {
        return $this->hasMany(UsuarioEmpresa::class, 'Id_Usuario');
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class, 'Id_Usuario');
    }

    /**
     * El trait Notifiable, por defecto, busca $this->email (minúscula) para
     * saber a dónde mandar notificaciones por el canal "mail". Nuestra
     * columna real es "Email" (con mayúscula) -> sin este método, Eloquent
     * nunca encuentra el destinatario y el correo se "envía" sin ir a
     * ningún lado, sin lanzar ninguna excepción visible.
     */
    public function routeNotificationForMail(\Illuminate\Notifications\Notification $notification): string
    {
        return $this->Email;
    }

    /**
     * ¿Este usuario tiene el rol indicado (por nombre) en la empresa dada?
     * El vínculo Usuario_Empresa debe estar activo.
     */
    public function tieneRolEnEmpresa(int $idEmpresa, string $nombreRol): bool
    {
        return $this->usuarioEmpresas()
            ->where('Id_Empresa', $idEmpresa)
            ->where('Activo', true)
            ->whereHas('rol', fn ($q) => $q->where('Nombre_Rol', $nombreRol))
            ->exists();
    }

    public function esSistemas(int $idEmpresa): bool
    {
        return $this->tieneRolEnEmpresa($idEmpresa, 'Sistemas');
    }

    public function esAdmin(int $idEmpresa): bool
    {
        return $this->tieneRolEnEmpresa($idEmpresa, 'Admin');
    }

   public function esCompras(int $idEmpresa): bool
    {
        return $this->tieneRolEnEmpresa($idEmpresa, 'Compras');
    }


    /**
     * ¿Este usuario tiene rol "Sistemas" en AL MENOS UNA empresa? Se usa como
     * permiso "global" para crear/gestionar usuarios internos, gestionar
     * empresas, y ver el catálogo completo de empresas, sin importar la
     * empresa activa actual de la sesión.
     */
    public function esSistemasGlobal(): bool
    {
        return $this->usuarioEmpresas()
            ->where('Activo', true)
            ->whereHas('rol', fn ($q) => $q->where('Nombre_Rol', 'Sistemas'))
            ->exists();
    }
}