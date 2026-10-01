<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Video tutorial que el proveedor ve desde su panel.
 *
 * LECTURA Y ESCRITURA ESTÁN EN RUTAS DISTINTAS a propósito: leerlo lo
 * necesita cualquier usuario logueado (es el proveedor quien abre el
 * modal), guardarlo es exclusivo de Sistemas. El permiso de escritura lo
 * valida el Service, no la ruta -mismo criterio que el resto del módulo-.
 */
class VideoTutorialController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService) {}

    public function show(): JsonResponse
    {
        return response()->json($this->configuracionService->obtenerVideoTutorial());
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            // 'present' y no 'required': mandar la cadena vacía es la
            // forma de quitar el video y apagar el botón.
            'url' => ['present', 'nullable', 'string', 'max:500'],
        ]);

        return response()->json(
            $this->configuracionService->definirVideoTutorial($request->user(), $request->input('url'))
        );
    }
}
