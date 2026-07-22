<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;

class PublicConfigController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService)
    {
    }

    public function homeSlides(): JsonResponse
    {
        return response()->json(
            $this->configuracionService->listarSlides()->where('Activo', true)->values()
        );
    }

    public function loginImagen(): JsonResponse
    {
        return response()->json(['url' => $this->configuracionService->obtenerImagenLogin()]);
    }

    public function guiaPasos(): JsonResponse
    {
        return response()->json(
            $this->configuracionService->listarPasosGuia()->where('Activo', true)->values()
        );
    }
}