<?php

namespace App\Modules\Pedidos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pedidos\Http\Resources\PedidoCompraResource;
use App\Modules\Pedidos\Services\PedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    public function __construct(protected PedidoService $pedidoService)
    {
    }

    public function abiertos(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $pedidos = $this->pedidoService->listar($request->user(), $idEmpresaActiva, 'Abierto');

        return response()->json(PedidoCompraResource::collection($pedidos));
    }

    public function cerrados(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $pedidos = $this->pedidoService->listar($request->user(), $idEmpresaActiva, 'Cerrado');

        return response()->json(PedidoCompraResource::collection($pedidos));
    }

    public function actualizar(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $total = $this->pedidoService->actualizar($request->user(), $idEmpresaActiva);

        return response()->json(['message' => "Se sincronizaron {$total} pedidos.", 'total' => $total]);
    }

    public function cerrar(Request $request, int $pedido): JsonResponse
    {
        $this->pedidoService->cerrar($pedido, $request->user());

        return response()->json(['message' => 'Pedido marcado como cerrado.']);
    }
}