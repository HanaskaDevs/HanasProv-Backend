<?php

namespace App\Shared;

/**
 * Convierte texto libre (razón social, nombre de producto, nombre de
 * empresa, etc.) en algo seguro para usar como nombre de carpeta o de
 * archivo: sin tildes, sin espacios, sin caracteres que puedan romper
 * una ruta o confundir a un humano leyendo el filesystem.
 */
class SaneadorNombreArchivo
{
    public static function sanear(?string $texto, string $valorPorDefecto = 'sin-dato'): string
    {
        if (! $texto) {
            return $valorPorDefecto;
        }

        // Sin tildes/diacríticos (José -> Jose), para no depender de que
        // el filesystem/terminal maneje bien UTF-8 en todos los casos.
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;

        // Espacios -> guion bajo, y solo se permite alfanumérico, guion
        // y guion bajo -> todo lo demás (tildes que hayan sobrevivido,
        // signos, "/", "..") se elimina directamente.
        $texto = str_replace(' ', '_', trim($texto));
        $texto = preg_replace('/[^A-Za-z0-9\-_]/', '', $texto) ?? '';

        // Evita nombres larguísimos si la razón social es muy extensa.
        $texto = substr($texto, 0, 80);

        return $texto !== '' ? $texto : $valorPorDefecto;
    }
}