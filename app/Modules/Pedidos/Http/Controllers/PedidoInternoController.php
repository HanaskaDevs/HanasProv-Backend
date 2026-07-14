<?php

namespace App\Modules\Pedidos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pedidos\Http\Requests\ActualizarRecepcionDetalleRequest;
use App\Modules\Pedidos\Http\Requests\RegistrarRecepcionRequest;
use App\Modules\Pedidos\Http\Resources\PedidoInternoResource;
use App\Modules\Pedidos\Http\Resources\RecepcionPedidoDetalleResource;
use App\Modules\Pedidos\Services\RecepcionPedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PedidoInternoController extends Controller
{
    public function __construct(protected RecepcionPedidoService $recepcionService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');
        $estado = $request->query('estado', 'Abierto');
        $busqueda = $request->query('busqueda');

        $pedidos = $this->recepcionService->listar($request->user(), $idEmpresaActiva, $estado, $busqueda);

        return response()->json(PedidoInternoResource::collection($pedidos));
    }

    public function show(Request $request, int $pedido): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $detalle = $this->recepcionService->detalle($request->user(), $idEmpresaActiva, $pedido);

        return response()->json(new PedidoInternoResource($detalle));
    }

    public function registrarRecepcion(RegistrarRecepcionRequest $request, int $pedido): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $recepcion = $this->recepcionService->registrar(
            $request->user(),
            $idEmpresaActiva,
            $pedido,
            $request->validated('fecha_recepcion'),
            $request->validated('lineas'),
        );

        return response()->json(['message' => 'Recepción registrada correctamente.', 'id_recepcion' => $recepcion->Id_Recepcion_Pedido], 201);
    }

    public function actualizarDetalle(ActualizarRecepcionDetalleRequest $request, int $detalle): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $detalleActualizado = $this->recepcionService->actualizarDetalle(
            $request->user(),
            $idEmpresaActiva,
            $detalle,
            $request->validated(),
        );

        return response()->json(new RecepcionPedidoDetalleResource($detalleActualizado));
    }

    public function cerrarPedido(Request $request, int $pedido): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $this->recepcionService->cerrarPedido($request->user(), $idEmpresaActiva, $pedido);

        return response()->json(['message' => 'Pedido cerrado correctamente.']);
    }

    public function verImagen(Request $request, int $imagen)
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        return $this->recepcionService->verImagen($request->user(), $idEmpresaActiva, $imagen);
    }
}