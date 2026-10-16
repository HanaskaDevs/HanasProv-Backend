<?php

namespace App\Modules\Documentos_Proveedor\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documentos_Proveedor\Services\PlanContingenciaNinoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quién cargó el Plan de contingencia contra el fenómeno del Niño.
 * Sistemas, Admin y Calidad (lo valida el service).
 */
class PlanContingenciaNinoController extends Controller
{
    public function __construct(protected PlanContingenciaNinoService $servicio)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->servicio->resumen($request->user(), (int) $request->attributes->get('id_empresa_activa'))
        );
    }
}
