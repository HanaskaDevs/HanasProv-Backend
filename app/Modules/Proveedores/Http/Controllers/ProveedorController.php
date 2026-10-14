<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Proveedores\Http\Requests\StoreProveedorRequest;
use App\Modules\Proveedores\Http\Resources\ProveedorResource;
use App\Modules\Proveedores\Services\ProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los permisos se validan en ProveedorService, no en las rutas: el grupo
 * solo garantiza sesión y empresa activa.
 */
class ProveedorController extends Controller
{
    public function __construct(protected ProveedorService $proveedorService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $proveedores = $this->proveedorService->listarPorEmpresa($request->user(), $idEmpresa);

        return response()->json(ProveedorResource::collection($proveedores));
    }

    public function store(StoreProveedorRequest $request): JsonResponse
    {
        $proveedor = $this->proveedorService->crear(
            $request->user(),
            (int) $request->attributes->get('id_empresa_activa'),
            $request->validated()
        );

        return response()->json(new ProveedorResource($proveedor), 201);
    }

    public function show(Request $request, int $proveedor): JsonResponse
    {
        $modelo = $this->proveedorService->obtener(
            $request->user(),
            (int) $request->attributes->get('id_empresa_activa'),
            $proveedor
        );

        return response()->json(new ProveedorResource($modelo));
    }
}
