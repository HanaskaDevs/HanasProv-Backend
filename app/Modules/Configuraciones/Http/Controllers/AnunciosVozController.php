<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Interruptor de los anuncios por voz del Modo TV del calendario de
 * entregas. Solo rol Sistemas (lo valida el service), igual que el resto
 * de esta sección.
 *
 * El Modo TV NO lee el interruptor desde acá: lo hace contra
 * /horarios-entrega/config-anuncios, porque quien tiene la TV abierta
 * suele ser el Guardia o Compras y no entra a Configuraciones. Acá vive
 * solo la parte que cambia el valor.
 */
class AnunciosVozController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'activa' => $this->configuracionService->obtenerAnunciosVoz(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $datos = $request->validate(['activa' => ['required', 'boolean']]);

        $this->configuracionService->definirAnunciosVoz($request->user(), $datos['activa']);

        return $this->show();
    }
}
