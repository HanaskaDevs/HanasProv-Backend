<?php

namespace App\Modules\Ficha_Productos\Models;

use Illuminate\Database\Eloquent\Model;

class TipoDocumentoProducto extends Model
{
    protected $table = 'Tipo_Documento_Producto';
    protected $primaryKey = 'Id_Tipo_Documento_Producto';
    public $timestamps = false;

    protected $fillable = [
        'Nombre_Documento', 'Carpeta_Slug', 'Codigo_Archivo', 'Obligatorio',
        'Permite_Multiples', 'Requiere_Fecha_Caducidad', 'Activo',
    ];

    protected $casts = [
        'Obligatorio' => 'boolean',
        'Permite_Multiples' => 'boolean',
        'Requiere_Fecha_Caducidad' => 'boolean',
        'Activo' => 'boolean',
    ];

    /**
     * Documentos que en un producto FRUVER dejan de ser obligatorios
     * (22-oct-2026): la Notificación sanitaria o Certificado de Inscripción
     * de alimentos. Se identifica por Codigo_Archivo porque el nombre se
     * edita desde el catálogo.
     */
    public const CODIGOS_OPCIONALES_EN_FRUVER = ['NSANITARIA'];

    /**
     * ¿Este documento es obligatorio para un producto de esta categoría?
     *
     * Es la ÚNICA regla: la usan el registro, la confirmación de
     * correcciones, el borrado y las pantallas (que la reciben ya resuelta
     * en 'obligatorio_en_fruver').
     */
    public function esObligatorioPara(?string $clasificacion): bool
    {
        if (! $this->Obligatorio) {
            return false;
        }

        return ! ($clasificacion === Producto::FRUVER && $this->esOpcionalEnFruver());
    }

    public function esOpcionalEnFruver(): bool
    {
        return in_array($this->Codigo_Archivo, self::CODIGOS_OPCIONALES_EN_FRUVER, true);
    }
}