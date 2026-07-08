<?php

namespace App\Modules\Ficha_Productos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Http\Requests\GuardarProductoRequest;
use App\Modules\Ficha_Productos\Http\Requests\SubirDocumentoProductoRequest;
use App\Modules\Ficha_Productos\Http\Resources\ProductoResource;
use App\Modules\Ficha_Productos\Services\ProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    public function __construct(protected ProductoService $productoService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(ProductoResource::collection($this->productoService->listar($request->user())));
    }

    public function store(GuardarProductoRequest $request): JsonResponse
    {
        $producto = $this->productoService->crear($request->user(), $request->validated());

        return response()->json(new ProductoResource($producto), 201);
    }

    public function subirDocumento(SubirDocumentoProductoRequest $request, int $producto, int $tipoDocumento): JsonResponse
    {
        $documento = $this->productoService->subirDocumento(
            $request->user(),
            $producto,
            $tipoDocumento,
            $request->file('archivo')
        );

        return response()->json($documento, 201);
    }
}