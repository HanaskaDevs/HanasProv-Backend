<?php

namespace App\Modules\Ficha_Productos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Http\Requests\RechazarCambioPrecioRequest;
use App\Modules\Ficha_Productos\Http\Requests\SolicitarCambioPrecioRequest;
use App\Modules\Ficha_Productos\Http\Resources\SolicitudCambioPrecioResource;
use App\Modules\Ficha_Productos\Services\SolicitudCambioPrecioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SolicitudCambioPrecioController extends Controller
{
    public function __construct(protected SolicitudCambioPrecioService $service) {}

    /**
     * El proveedor (ya Aprobado) solicita cambiar el precio de un producto
     * suyo. Bloquea el precio hasta que Admin/Calidad de la empresa lo
     * resuelva.
     */
    public function solicitar(SolicitarCambioPrecioRequest $request, int $producto): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $solicitud = $this->service->solicitar(
            $request->user(),
            $idEmpresaActiva,
            $producto,
            (float) $request->validated('precio_nuevo')
        );

        return response()->json(new SolicitudCambioPrecioResource($solicitud), 201);
    }

    /**
     * Admin/Calidad: lista las solicitudes de cambio de precio pendientes
     * de la empresa activa.
     */
    public function index(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $solicitudes = $this->service->listarPendientes($request->user(), $idEmpresaActiva);

        return response()->json(SolicitudCambioPrecioResource::collection($solicitudes));
    }

    public function aprobar(Request $request, int $solicitud): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $resultado = $this->service->aprobar($request->user(), $idEmpresaActiva, $solicitud);

        return response()->json(new SolicitudCambioPrecioResource($resultado));
    }

    public function rechazar(RechazarCambioPrecioRequest $request, int $solicitud): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $resultado = $this->service->rechazar(
            $request->user(),
            $idEmpresaActiva,
            $solicitud,
            $request->validated('motivo')
        );

        return response()->json(new SolicitudCambioPrecioResource($resultado));
    }
}
