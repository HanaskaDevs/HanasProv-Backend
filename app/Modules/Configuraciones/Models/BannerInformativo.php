<?php

namespace App\Modules\Configuraciones\Models;

use App\Models\BaseModel;

/**
 * Una pieza del banner informativo: una imagen o un video, con su texto.
 * El interruptor general y el título del banner viven en Configuracion.
 */
class BannerInformativo extends BaseModel
{
    protected $table = 'Banner_Informativo';

    protected $primaryKey = 'Id_Banner_Informativo';

    public $timestamps = false;

    protected $fillable = [
        'Orden',
        'Titulo',
        'Descripcion',
        'Ruta_Media',
        'Tipo_Media',
        'Activo',
        'Creado_Por',
        'Fecha_Creacion',
        'Modificado_Por',
        'Fecha_Modificacion',
    ];

    protected $casts = [
        'Activo' => 'boolean',
    ];
}
