<?php

namespace App\Modules\Asistente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistente\Http\Requests\EnviarMensajeRequest;
use App\Modules\Asistente\Services\AsistenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsistenteController extends Controller
{
    public function __construct(protected AsistenteService $asistenteService)
    {
    }

    public function enviarMensaje(EnviarMensajeRequest $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $resultado = $this->asistenteService->responder(
            $request->user(),
            $idEmpresaActiva,
            $request->validated('mensaje'),
            $request->validated('historial', []),
        );

        // 'respuesta' se mantiene con ese nombre para no romper el frontend
        // que ya lo lee. 'tabla' es nuevo: viene con filas cuando el modelo
        // usó una herramienta de consulta, y es lo que habilita el botón de
        // descarga en Excel bajo el mensaje.
        return response()->json([
            'respuesta' => $resultado['texto'],
            'tabla' => $resultado['tabla'],
        ]);
    }

    /**
     * Se llama sola al cargar el dashboard (ver HanaBot.tsx), sin que el
     * usuario abra el chat. Devuelve null casi siempre; solo trae texto
     * la primera vez que un proveedor recién Aprobado entra al portal.
     */
    public function bienvenidaProactiva(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $mensaje = $this->asistenteService->obtenerBienvenidaProactiva($request->user(), $idEmpresaActiva);

        return response()->json(['mensaje' => $mensaje]);
    }
}