<?php

namespace App\Modules\Proveedores\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoProveedor extends Model
{
    protected $table = 'Estado_Proveedor';
    protected $primaryKey = 'Id_Estado_Proveedor';
    public $timestamps = false;

    /**
     * IDs fijos de Estado_Proveedor. Antes vivían repetidos como
     * constantes protected en 4 servicios distintos (Calificacion,
     * FichaProveedor, SolicitudCambioPrecio, Asistente) y como un `1`
     * pelado con un TODO en otros 3 lugares -> el TODO que dejaban esos
     * comentarios ("usar constante/enum del estado") se resuelve acá.
     *
     * Van en el modelo que ya es dueño de la tabla, no en un enum nuevo:
     * el proyecto no usa enums en ningún módulo y esto no necesita
     * ninguna estructura nueva para leerse igual de claro.
     *
     * Estas filas NO tienen CRUD desde la interfaz a propósito, y el
     * seeder las inserta siempre en este orden para que las IDENTITY
     * calcen con estos valores -> ver RolEstadoProveedorSeeder, que
     * explica por qué el orden importa. Si alguna vez alguien reordena o
     * borra esas filas, esto es el único lugar que hay que corregir.
     */
    public const ASPIRANTE = 1;
    public const APROBADO = 2;
    public const RECHAZADO = 3;
    public const SUSPENDIDO = 4;

    protected $fillable = ['Nombre_Estado', 'Descripcion', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];
}
