<?php

namespace App\Modules\Ficha_Productos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use Illuminate\Http\JsonResponse;

class UnidadPresentacionController extends Controller
{
    public function index(): JsonResponse
    {
        $unidades = UnidadPresentacion::where('Activo', 1)->get(['Id_Unidad_Presentacion', 'Nombre_Unidad']);

        return response()->json($unidades);
    }
}