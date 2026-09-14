<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;

class ClaseProveedor extends Model
{
    protected $table = 'Clase_Proveedor';
    protected $primaryKey = 'Id_Clase_Proveedor';
    public $timestamps = false;

    /**
     * Codigo_BC: el portal maneja sus propios nombres de clase, pero BC
     * solo acepta los 4 valores de su enum de Tipo Proveedor. NULL
     * significa "en BC va EN BLANCO" (caso Servicio), no "falta el dato".
     */
    protected $fillable = ['Nombre_Clase', 'Codigo_BC', 'Icono_Url', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];

    /**
     * Clase de servicios: es la unica que se aprueba SIN necesidad de
     * tener productos aprobados (pedido explicito del usuario) -> un
     * proveedor de servicios no carga catalogo de productos.
     */
    public const SERVICIO = 'Servicio';

    public function esServicio(): bool
    {
        return $this->Nombre_Clase === self::SERVICIO;
    }
}
