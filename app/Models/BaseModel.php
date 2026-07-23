<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo base para toda la app.
 *
 * IMPORTANTE: sin $dateFormat, Eloquent serializa cualquier atributo con
 * cast 'date'/'datetime' usando el formato por defecto del grammar de
 * sqlsrv ("Y-m-d H:i:s..." con guiones). Ese formato es AMBIGUO para SQL
 * Server: su interpretación depende del DATEFORMAT/idioma de la sesión
 * (en esta app, 'language' => 'Spanish' => DATEFORMAT dmy), lo que puede
 * producir "SQLSTATE[22007] ... La conversión del tipo de datos nvarchar
 * en datetime produjo un valor fuera de intervalo" al hacer INSERT/UPDATE.
 *
 * "Y-m-d\TH:i:s" (formato ISO 8601 con separador T) SQL Server lo
 * interpreta SIEMPRE como año-mes-día, sin importar esa configuración de
 * sesión. Todos los modelos de la app deben extender de este BaseModel
 * en vez de Illuminate\Database\Eloquent\Model directamente.
 */
abstract class BaseModel extends Model
{
    protected $dateFormat = 'Y-m-d\TH:i:s';
}