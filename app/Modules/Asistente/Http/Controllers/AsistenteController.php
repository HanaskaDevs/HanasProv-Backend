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
}