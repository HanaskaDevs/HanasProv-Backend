<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Http\Requests\GuardarHomeSlideRequest;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeSlideController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->configuracionService->listarSlides());
    }

    public function store(GuardarHomeSlideRequest $request): JsonResponse
    {
        $slide = $this->configuracionService->crearSlide(
            $request->user(),
            $request->validated(),
            $request->file('media'),
        );

        return response()->json($slide, 201);
    }

    public function update(GuardarHomeSlideRequest $request, int $slide): JsonResponse
    {
        $actualizado = $this->configuracionService->actualizarSlide(
            $request->user(),
            $slide,
            $request->validated(),
            $request->file('media'),
        );

        return response()->json($actualizado);
    }

    public function destroy(Request $request, int $slide): JsonResponse
    {
        $this->configuracionService->eliminarSlide($request->user(), $slide);

        return response()->json(['message' => 'Slide eliminado correctamente.']);
    }
}