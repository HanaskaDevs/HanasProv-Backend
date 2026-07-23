<?php

namespace App\Modules\Pedidos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pedidos\Http\Resources\PedidoCompraResource;
use App\Modules\Pedidos\Services\PedidoInternoService;
use App\Modules\Pedidos\Services\PedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    public function __construct(
        protected PedidoService $pedidoService,
        protected PedidoInternoService $pedidoInternoService,
    ) {}

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

    /**
     * Vista interna (Admin): todos los pedidos agrupados por bodega
     * (CD-0001 / CD-0002 / CD-0003), leídos directo de BC_*.
     * Sin distinción Vigentes/Históricos -> eso es exclusivo del proveedor.
     */
    public function internos(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $filtros = $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'proveedor' => ['nullable', 'string', 'max:150'],
            'producto' => ['nullable', 'string', 'max:150'],
        ]);

        $pedidosPorBodega = $this->pedidoInternoService->listarPorBodega(
            $request->user(),
            $idEmpresaActiva,
            $filtros
        );

        return response()->json($pedidosPorBodega);
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

    public function descargarPdf(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');
        $pedidos = $this->pedidoService->obtenerParaPdf($request->user(), $idEmpresaActiva, $data['ids']);

        if ($pedidos->isEmpty()) {
            abort(404, 'No se encontraron pedidos para descargar.');
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.pedidos', [
            'pedidos' => $pedidos,
            'nombreEmpresa' => $pedidos->first()->empresa->Razon_Social,
            'fechaGeneracion' => now()->format('Y-m-d H:i'),
        ]);

        $nombreArchivo = $pedidos->count() === 1
            ? "pedido-{$pedidos->first()->Nro_Pedido}.pdf"
            : 'pedidos-' . now()->format('Ymd_His') . '.pdf';

        return $pdf->download($nombreArchivo);
    }
}