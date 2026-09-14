<?php

namespace App\Modules\Documentos_Proveedor\Models;

use App\Modules\Proveedores\Models\ClaseProveedor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivote "esta Clase de Proveedor NO necesita este Tipo_Documento" ->
 * ej. LUAE excluido para la Clase "Productor Agrícola". Ver
 * DocumentoProveedorService::idsTiposDocumentoExcluidosPorClase().
 */
class TipoDocumentoClaseExcluida extends Model
{
    protected $table = 'Tipo_Documento_Clase_Excluida';
    protected $primaryKey = 'Id_Tipo_Documento_Clase_Excluida';
    public $timestamps = false;

    protected $fillable = ['Id_Tipo_Documento', 'Id_Clase_Proveedor', 'Activo'];

    protected $casts = ['Activo' => 'boolean'];

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'Id_Tipo_Documento');
    }

    public function clase(): BelongsTo
    {
        return $this->belongsTo(ClaseProveedor::class, 'Id_Clase_Proveedor');
    }
}
