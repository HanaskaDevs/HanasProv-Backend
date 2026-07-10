<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Proveedores\Http\Requests\GuardarSeccion1Request;
use App\Modules\Proveedores\Http\Requests\GuardarSeccion2Request;
use App\Modules\Proveedores\Http\Requests\GuardarSeccion3Request;
use App\Modules\Proveedores\Http\Resources\FichaProveedorResource;
use App\Modules\Proveedores\Services\FichaProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mi Ficha": el usuario externo (Proveedor) solo puede ver/editar la SUYA,
 * y específicamente la del Proveedor asociado a la empresa activa de su
 * sesión (un usuario puede estar vinculado a Proveedores de más de una
 * empresa). Nunca se recibe un Id_Proveedor por parámetro -> se resuelve
 * siempre desde el usuario autenticado + empresa activa.
 */
class FichaProveedorController extends Controller
{
    public function __construct(protected FichaProveedorService $fichaService)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $proveedor = $this->fichaService->obtenerMiFicha($request->user(), $idEmpresa);

        return response()->json(new FichaProveedorResource($proveedor));
    }

    public function seccion1(GuardarSeccion1Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $proveedor = $this->fichaService->guardarSeccion1($request->user(), $idEmpresa, $request->validated());

        return response()->json(new FichaProveedorResource($proveedor));
    }

    public function seccion2(GuardarSeccion2Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $proveedor = $this->fichaService->guardarSeccion2($request->user(), $idEmpresa, $request->validated('id_clases'));

        return response()->json(new FichaProveedorResource($proveedor));
    }

    public function seccion3(GuardarSeccion3Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $proveedor = $this->fichaService->guardarSeccion3($request->user(), $idEmpresa, $request->validated('id_categorias'));

        return response()->json(new FichaProveedorResource($proveedor));
    }
}
