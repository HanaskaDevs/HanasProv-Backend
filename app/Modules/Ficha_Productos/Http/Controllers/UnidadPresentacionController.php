<?php

namespace App\Modules\Ficha_Productos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use Illuminate\Http\JsonResponse;

class UnidadPresentacionController extends Controller
{
    public function index(): JsonResponse
    {
        // Ordenado por nombre y no por id: al agregarse "Litro" (12-sep-2026)
        // el desplegable quedaba "Unidad, Kilogramo, Paquete, Litro", que es
        // el orden en que se fueron creando y para quien lo lee no es
        // ningún orden. Alfabético es predecible y no hay que mantenerlo.
        $unidades = UnidadPresentacion::where('Activo', 1)
            ->orderBy('Nombre_Unidad')
            ->get(['Id_Unidad_Presentacion', 'Nombre_Unidad']);

        return response()->json($unidades);
    }
}