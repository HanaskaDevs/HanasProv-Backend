<?php

namespace App\Shared;

/**
 * Cómo se escribe y se valida el precio de un producto (18-nov-2026).
 *
 * El precio admite hasta 4 decimales (Producto.Precio es DECIMAL(12,4),
 * ver la migración 2026_11_18_090000). Las reglas y el formato viven acá,
 * en un solo lugar, porque el precio entra por tres caminos -el formulario
 * del producto, la solicitud de cambio de precio y el Excel de Catálogo- y
 * los tres tienen que aceptar y mostrar exactamente lo mismo.
 */
class FormatoPrecio
{
    /** Decimales que admite la columna. */
    public const DECIMALES = 4;

    /** Tope de DECIMAL(12,4): 8 dígitos enteros. */
    public const MAXIMO = 99999999.9999;

    /**
     * Reglas de validación de un precio, sin el required/nullable (eso lo
     * decide cada formulario).
     *
     * @return array<int, string>
     */
    public static function reglas(float $minimo = 0): array
    {
        return ['numeric', 'decimal:0,'.self::DECIMALES, 'min:'.$minimo, 'max:'.self::MAXIMO];
    }

    /**
     * El precio para MOSTRAR: siempre 2 decimales como mínimo y hasta 4 si
     * los tiene. 5 -> "5.00", 0.1275 -> "0.1275", 1.250 -> "1.25". Así los
     * precios de siempre se siguen leyendo igual y solo crecen los que de
     * verdad traen más precisión.
     */
    public static function mostrar(mixed $precio): string
    {
        if ($precio === null || $precio === '') {
            return '';
        }

        $texto = number_format((float) $precio, self::DECIMALES, '.', '');

        // Quita hasta dos ceros finales: de 4 decimales baja como mucho a 2.
        return preg_replace('/0{1,2}$/', '', $texto);
    }
}
