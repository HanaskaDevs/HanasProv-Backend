<?php

namespace App\Shared;

use Illuminate\Validation\Rules\Password;

/**
 * Regla única de contraseña para todo el portal.
 *
 * Mínimo 8 caracteres, al menos un número y al menos un carácter especial
 * (decisión del negocio, 28-ago-2026). Antes solo se pedía 'min:8', así que
 * "12345678" era una contraseña válida.
 *
 * SOLO APLICA A CONTRASEÑAS NUEVAS. A los usuarios que ya existen no se les
 * toca nada ni se les fuerza un cambio: la regla se evalúa cuando alguien
 * define una contraseña (activar cuenta, recuperar acceso, cambiarla a
 * mano), nunca al iniciar sesión. Un usuario viejo con una clave débil
 * sigue entrando igual, y recién tendrá que cumplirla el día que la cambie.
 *
 * Vive en un solo lugar a propósito: si mañana se agrega el requisito de
 * mayúsculas, se toca acá y vale para los tres formularios de una vez.
 */
class ReglaPasswordSegura
{
    public static function regla(): Password
    {
        return Password::min(8)
            ->numbers()
            ->symbols();
    }

    /**
     * Texto para mostrarle al usuario. Se expone desde acá para que el
     * mensaje del formulario y la validación real no se puedan desincronizar.
     */
    public static function descripcion(): string
    {
        return 'La contraseña debe tener al menos 8 caracteres, incluir un número y un carácter especial.';
    }
}
