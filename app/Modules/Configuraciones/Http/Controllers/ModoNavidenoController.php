<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Interruptor del modo navideño (04-nov-2026). Solo rol Sistemas (lo valida
 * el service), igual que el resto de Configuraciones. El portal LEE el valor
 * por /public-config/modo-navideno, sin sesión, porque también decora el login.
 */
class ModoNavidenoController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json(['activo' => $this->configuracionService->obtenerModoNavideno()]);
    }

    public function update(Request $request): JsonResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);

        $this->configuracionService->definirModoNavideno($request->user(), $datos['activo']);

        return $this->show();
    }
}
