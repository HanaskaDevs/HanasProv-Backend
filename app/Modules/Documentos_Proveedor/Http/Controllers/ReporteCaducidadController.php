<?php

namespace App\Modules\Documentos_Proveedor\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documentos_Proveedor\Services\ReporteCaducidadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reporte de caducidad de documentos. Admin, Calidad y Sistemas (lo valida
 * el service, como en el resto de los módulos).
 */
class ReporteCaducidadController extends Controller
{
    public function __construct(protected ReporteCaducidadService $servicio)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return response()->json([
            // Los tramos viajan con los datos para que la pantalla no tenga
            // que repetir los cortes (7/15/30 días) ni sus nombres: si mañana
            // se cambian, se cambian en un solo lugar.
            'tramos' => ReporteCaducidadService::TRAMOS,
            'documentos' => $this->servicio->listar($request->user(), $idEmpresa),
        ]);
    }
}
