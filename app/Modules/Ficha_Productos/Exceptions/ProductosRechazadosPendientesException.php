<?php

namespace App\Modules\Ficha_Productos\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Se lanza al intentar "Registrar productos actualizados" cuando todavía
 * queda al menos un producto en estado Rechazado. A diferencia de una
 * ValidationException común (que solo da un mensaje de texto), acá el
 * frontend necesita saber EXACTAMENTE cuáles productos faltan -> así
 * puede ofrecer un acceso directo a corregir cada uno, no solo avisar
 * cuántos son.
 */
class ProductosRechazadosPendientesException extends RuntimeException
{
    /**
     * @param array<int, array{id_producto:int, nombre_producto:string, comentario_calificacion:?string}> $productos
     */
    public function __construct(protected array $productos)
    {
        parent::__construct('Todavía tienes productos rechazados por corregir.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'productos_rechazados' => $this->productos,
        ], 422);
    }
}