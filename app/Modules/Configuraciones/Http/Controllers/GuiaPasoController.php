<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Http\Requests\GuardarGuiaPasoRequest;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuiaPasoController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->configuracionService->listarPasosGuia());
    }

    public function store(GuardarGuiaPasoRequest $request): JsonResponse
    {
        $paso = $this->configuracionService->crearPasoGuia($request->user(), $request->validated());

        return response()->json($paso, 201);
    }

    public function update(GuardarGuiaPasoRequest $request, int $paso): JsonResponse
    {
        $actualizado = $this->configuracionService->actualizarPasoGuia($request->user(), $paso, $request->validated());

        return response()->json($actualizado);
    }

    public function destroy(Request $request, int $paso): JsonResponse
    {
        $this->configuracionService->eliminarPasoGuia($request->user(), $paso);

        return response()->json(['message' => 'Paso eliminado correctamente.']);
    }
}