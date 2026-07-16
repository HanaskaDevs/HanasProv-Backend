<?php

namespace App\Modules\Reclamos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reclamos\Http\Requests\ResponderReclamoRequest;
use App\Modules\Reclamos\Http\Resources\ReclamoMensajeResource;
use App\Modules\Reclamos\Http\Resources\ReclamoResource;
use App\Modules\Reclamos\Services\ReclamoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReclamoProveedorController extends Controller
{
    public function __construct(protected ReclamoService $reclamoService) {}

    public function abiertos(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $reclamos = $this->reclamoService->listarProveedor($request->user(), $idEmpresaActiva, 'Abierto');

        return response()->json(ReclamoResource::collection($reclamos));
    }

    public function cerrados(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $reclamos = $this->reclamoService->listarProveedor($request->user(), $idEmpresaActiva, 'Cerrado');

        return response()->json(ReclamoResource::collection($reclamos));
    }

    public function show(Request $request, int $reclamo): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $detalle = $this->reclamoService->detalle($request->user(), $idEmpresaActiva, $reclamo);

        return response()->json(new ReclamoResource($detalle));
    }

    public function responder(ResponderReclamoRequest $request, int $reclamo): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $mensaje = $this->reclamoService->responder(
            $request->user(),
            $idEmpresaActiva,
            $reclamo,
            $request->validated('mensaje'),
            $request->file('imagenes', []),
        );

        return response()->json(new ReclamoMensajeResource($mensaje), 201);
    }
}
