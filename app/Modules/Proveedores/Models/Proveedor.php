<?php

namespace App\Modules\Proveedores\Models;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends BaseModel
{
    protected $table = 'Proveedor';
    protected $primaryKey = 'Id_Proveedor';
    public $timestamps = false;

    protected $fillable = [
        'Id_Empresa', 'Id_Estado_Proveedor', 'Ruc', 'Clase_Contribuyente',
        'Razon_Social', 'Nombre_Comercial', 'Email', 'Telefono', 'Direccion',
        'Ciudad', 'Pagina_Web', 'Latitud', 'Longitud',
        'Representante_Legal', 'Correo_Representante', 'Telefono_Representante',
        'Contacto_Venta', 'Correo_Venta', 'Telefono_Contacto_Venta',
        'Contacto_Calidad', 'Correo_Calidad', 'Telefono_Contacto_Calidad',
        'Contacto_Contabilidad', 'Correo_Contabilidad', 'Telefono_Contabilidad',
        'Seccion_Actual', 'Porcentaje_Completado_Ficha',
        'Fecha_Registro_Documentacion', 'Fecha_Registro_Calificacion_Documentos', 'Correcciones_Pendientes',
        'Estado_Calificacion_Ficha', 'Comentario_Calificacion_Ficha',
        'Calificado_Por_Ficha', 'Fecha_Calificacion_Ficha',
        'Fecha_Postulacion', 'Fecha_Aprobacion',
        'Activo', 'Creado_Por', 'Fecha_Creacion', 'Modificado_Por', 'Fecha_Modificacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
        'Latitud' => 'decimal:7',
        'Longitud' => 'decimal:7',
        'Fecha_Registro_Documentacion' => 'datetime',
        'Fecha_Registro_Calificacion_Documentos' => 'datetime',
        'Correcciones_Pendientes' => 'boolean',
        'Fecha_Calificacion_Ficha' => 'datetime',
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

    /**
     * Usuarios externos vinculados a este Proveedor vía Usuario_Proveedor.
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'Usuario_Proveedor', 'Id_Proveedor', 'Id_Usuario')
            ->withPivot(['Activo', 'Id_Usuario_Proveedor'])
            ->using(\App\Modules\Auth\Models\UsuarioProveedor::class);
    }

    /**
     * Sección 2 de la Ficha: multi-select de Clase de Proveedor.
     */
    public function clases(): BelongsToMany
    {
        return $this->belongsToMany(
            ClaseProveedor::class,
            'Proveedor_Clase',
            'Id_Proveedor',
            'Id_Clase_Proveedor'
        )->withPivot(['Activo', 'Id_Proveedor_Clase']);
    }

    /**
     * Sección 3 de la Ficha: multi-select de Categoría de Productos/Servicios.
     */
    public function categoriasProducto(): BelongsToMany
    {
        return $this->belongsToMany(
            CategoriaProducto::class,
            'Proveedor_Categoria_Producto',
            'Id_Proveedor',
            'Id_Categoria_Producto'
        )->withPivot(['Activo', 'Id_Proveedor_Categoria']);
    }

    /**
     * Calificación campo-por-campo de la Ficha (admin). Ver
     * CalificacionProveedorService para el detalle de cómo se usa.
     */
    public function calificacionesCampos(): HasMany
    {
        return $this->hasMany(CalificacionCampoFicha::class, 'Id_Proveedor');
    }

   public function documentos(): HasMany
{
    return $this->hasMany(\App\Modules\Documentos_Proveedor\Models\DocumentoProveedor::class, 'Id_Proveedor');
}

    public function historialEstados(): HasMany
    {
        return $this->hasMany(HistorialEstadoProveedor::class, 'Id_Proveedor');
    }

    public function archivos(): HasMany
{
    return $this->hasMany(\App\Modules\Documentos_Proveedor\Models\Archivo::class, 'Id_Proveedor');
}

    public function aceptacionesNormativa(): HasMany
    {
        return $this->hasMany(AceptacionNormativa::class, 'Id_Proveedor');
    }

    public function productos(): HasMany
{
    return $this->hasMany(\App\Modules\Ficha_Productos\Models\Producto::class, 'Id_Proveedor');
}

    /**
     * Estado GENERAL de la ficha, derivado de las calificaciones campo
     * por campo (ya no existe un solo "Aprobado/Rechazado" para toda la
     * ficha, se calcula a partir de calificacionesCampos):
     * - 'Rechazado': al menos un campo fue rechazado.
     * - 'Aprobado': TODOS los campos calificables ya están calificados y
     *   ninguno fue rechazado.
     * - null: todavía no se calificó nada, o está parcialmente calificada
     *   (algunos campos sí, otros no, sin ningún rechazo) -> se trata
     *   como "en revisión" en el front.
     * Requiere que 'calificacionesCampos' venga cargado (eager load),
     * si no, dispara una query lazy por cada Proveedor listado.
     */
    public function estadoGeneralCalificacionFicha(): ?string
    {
        $calificaciones = $this->calificacionesCampos;

        if ($calificaciones->isEmpty()) {
            return null;
        }

        if ($calificaciones->contains(fn (CalificacionCampoFicha $c) => $c->Estado === 'Rechazado')) {
            return 'Rechazado';
        }

        $totalCamposCalificables = count(\App\Modules\Proveedores\Services\CalificacionProveedorService::CAMPOS_SECCION1) + 2;

        if (
            $calificaciones->count() >= $totalCamposCalificables
            && $calificaciones->every(fn (CalificacionCampoFicha $c) => $c->Estado === 'Aprobado')
        ) {
            return 'Aprobado';
        }

        return null;
    }
}