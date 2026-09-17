<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Proveedores\Http\Requests\GuardarContactosRequest;
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

    public function contactos(GuardarContactosRequest $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $proveedor = $this->fichaService->guardarContactosAprobado($request->user(), $idEmpresa, $request->validated());

        return response()->json(new FichaProveedorResource($proveedor));
    }

    public function seccion1(GuardarSeccion1Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        // acepta_politicas: casilla "acepto las Políticas de Hanaska". Solo
        // cuenta cuando este guardado completa la ficha; ver el Service.
        $proveedor = $this->fichaService->guardarSeccion1(
            $request->user(),
            $idEmpresa,
            $request->safe()->except('acepta_politicas'),
            $request->boolean('acepta_politicas')
        );

        return response()->json(new FichaProveedorResource($proveedor));
    }

    public function seccion2(GuardarSeccion2Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $proveedor = $this->fichaService->guardarSeccion2(
            $request->user(),
            $idEmpresa,
            $request->validated('id_clases'),
            $request->boolean('acepta_politicas')
        );

        return response()->json(new FichaProveedorResource($proveedor));
    }

    public function seccion3(GuardarSeccion3Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $proveedor = $this->fichaService->guardarSeccion3(
            $request->user(),
            $idEmpresa,
            $request->validated('id_categorias'),
            $request->boolean('acepta_politicas')
        );

        return response()->json(new FichaProveedorResource($proveedor));
    }

    /**
     * Cuenta bancaria declarada por el proveedor.
     *
     * ENVUELTA EN {"cuenta": ...} Y NO DEVUELTA PELADA, que es lo que hacía
     * antes. `response()->json(null)` NO produce `null`: Symfony convierte
     * el null en un ArrayObject vacío y el cuerpo sale como `{}`. Del otro
     * lado, `{}` es un objeto TRUTHY, así que la pantalla daba por
     * registrada una cuenta inexistente y dibujaba "Información de su
     * cuenta completa" con los tres campos en blanco.
     *
     * Con el envoltorio, "no tiene cuenta" viaja como {"cuenta":null} y no
     * hay forma de confundirlo con una cuenta cargada.
     */
    public function cuentaBancaria(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return response()->json([
            'cuenta' => $this->fichaService->obtenerMiCuentaBancaria($request->user(), $idEmpresa),
        ]);
    }

    /** Misma forma que el GET, para que el front la lea igual en los dos casos. */
    public function guardarCuentaBancaria(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return response()->json([
            'cuenta' => $this->fichaService->guardarMiCuentaBancaria($request->user(), $idEmpresa, $request->all()),
        ]);
    }
}