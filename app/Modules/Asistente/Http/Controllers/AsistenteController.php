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

        $respuesta = $this->asistenteService->responder(
            $request->user(),
            $idEmpresaActiva,
            $request->validated('mensaje'),
            $request->validated('historial', []),
        );

        return response()->json(['respuesta' => $respuesta]);
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