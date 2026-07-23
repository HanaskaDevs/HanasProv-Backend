<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Proveedores\Http\Requests\StoreProveedorRequest;
use App\Modules\Proveedores\Http\Resources\ProveedorResource;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\ProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProveedorController extends Controller
{
    public function __construct(protected ProveedorService $proveedorService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $idEmpresa = $request->attributes->get('id_empresa_activa');

        $proveedores = $this->proveedorService->listarPorEmpresa($idEmpresa);

        return response()->json(ProveedorResource::collection($proveedores));
    }

    public function store(StoreProveedorRequest $request): JsonResponse
    {
        $proveedor = Proveedor::create([
            ...$request->validated(),
            'Id_Empresa' => $request->attributes->get('id_empresa_activa'),
            'Id_Estado_Proveedor' => 1, // TODO: usar constante/enum del estado "Aspirante" inicial
            'Seccion_Actual' => 1,
            'Porcentaje_Completado_Ficha' => 0,
            'Fecha_Postulacion' => now(),
            'Activo' => true,
        ]);

        return response()->json(new ProveedorResource($proveedor), 201);
    }

    public function show(Proveedor $proveedor): JsonResponse
    {
        return response()->json(
            new ProveedorResource($proveedor->load(['estado', 'clases', 'categoriasProducto']))
        );
    }
}