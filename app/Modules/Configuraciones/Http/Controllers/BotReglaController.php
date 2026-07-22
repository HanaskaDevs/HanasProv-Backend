<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Http\Requests\GuardarBotReglaRequest;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotReglaController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->configuracionService->listarReglasBot());
    }

    public function store(GuardarBotReglaRequest $request): JsonResponse
    {
        $regla = $this->configuracionService->crearReglaBot($request->user(), $request->validated());

        return response()->json($regla, 201);
    }

    public function update(GuardarBotReglaRequest $request, int $regla): JsonResponse
    {
        $actualizado = $this->configuracionService->actualizarReglaBot($request->user(), $regla, $request->validated());

        return response()->json($actualizado);
    }

    public function destroy(Request $request, int $regla): JsonResponse
    {
        $this->configuracionService->eliminarReglaBot($request->user(), $regla);

        return response()->json(['message' => 'Regla eliminada correctamente.']);
    }
}