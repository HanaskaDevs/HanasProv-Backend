<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Services\DashboardSistemasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DashboardSistemasController extends Controller
{
    public function __construct(protected DashboardSistemasService $dashboardService)
    {
    }

    public function resumen(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        if (! $request->user()->esSistemasGlobal() && ! $request->user()->esAdmin($idEmpresaActiva)) {
            throw new AccessDeniedHttpException('No tienes permisos para ver este resumen.');
        }

        return response()->json($this->dashboardService->obtenerResumen($idEmpresaActiva));
    }
}