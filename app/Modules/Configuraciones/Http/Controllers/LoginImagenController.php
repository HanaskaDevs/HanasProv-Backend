<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginImagenController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json(['url' => $this->configuracionService->obtenerImagenLogin()]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'imagen' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $ruta = $this->configuracionService->actualizarImagenLogin($request->user(), $request->file('imagen'));

        return response()->json(['url' => $ruta]);
    }
}