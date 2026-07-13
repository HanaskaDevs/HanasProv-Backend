<?php

namespace App\Modules\Auth\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    // SOLUCIÓN: Aplicamos el formato seguro al modelo interno de los tokens
    protected $dateFormat = 'Ymd H:i:s';
}