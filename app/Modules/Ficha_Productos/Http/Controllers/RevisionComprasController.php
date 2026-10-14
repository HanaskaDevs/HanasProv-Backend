<?php

namespace App\Modules\Ficha_Productos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Http\Resources\ProductoResource;
use App\Modules\Ficha_Productos\Services\RevisionComprasProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja del PRIMER paso del circuito: los productos que el proveedor
 * envió y esperan a Compras.
 *
 * Los permisos los verifica el Service, no estas rutas -mismo criterio que
 * el resto del portal-, así que un endpoint nuevo acá nace protegido.
 */
class RevisionComprasController extends Controller
{
    public function __construct(protected RevisionComprasProductoService $revision)
    {
    }

    public function pendientes(Request $request): JsonResponse
    {
        $productos = $this->revision->pendientes($request->user(), $this->empresa($request));

        return response()->json(ProductoResource::collection($productos)->resolve());
    }

    public function aprobar(Request $request, int $producto): JsonResponse
    {
        $actualizado = $this->revision->aprobar($request->user(), $this->empresa($request), $producto);

        return response()->json([
            'message' => 'Producto aprobado. Pasó a la revisión de Calidad.',
            'producto' => new ProductoResource($actualizado),
        ]);
    }

    public function aprobarVarios(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'productos' => ['required', 'array', 'min:1', 'max:100'],
            'productos.*' => ['integer', 'distinct'],
        ], [
            'productos.required' => 'Selecciona al menos un producto.',
            'productos.min' => 'Selecciona al menos un producto.',
            'productos.max' => 'Se pueden aprobar hasta 100 productos por vez.',
        ]);

        $aprobados = $this->revision->aprobarVarios($request->user(), $this->empresa($request), $datos['productos']);
        $cuantos = $aprobados->count();

        return response()->json([
            'message' => $cuantos === 1
                ? 'Producto aprobado. Pasó a la revisión de Calidad.'
                : "{$cuantos} productos aprobados. Pasaron a la revisión de Calidad.",
            'aprobados' => $cuantos,
        ]);
    }

    public function rechazar(Request $request, int $producto): JsonResponse
    {
        $datos = $this->validarObservacion($request);

        $actualizado = $this->revision->rechazar(
            $request->user(),
            $this->empresa($request),
            $producto,
            $datos['observacion']
        );

        return response()->json([
            'message' => 'Producto devuelto al proveedor para que lo corrija.',
            'producto' => new ProductoResource($actualizado),
        ]);
    }

    public function eliminar(Request $request, int $producto): JsonResponse
    {
        $datos = $this->validarObservacion($request);

        $this->revision->eliminar($request->user(), $this->empresa($request), $producto, $datos['observacion']);

        return response()->json(['message' => 'Producto retirado del catálogo. Se le avisó al proveedor.']);
    }

    /**
     * La observación es OBLIGATORIA en las dos acciones negativas: es el
     * único texto que el proveedor va a recibir para saber qué corregir.
     * El mínimo de 10 caracteres corta los "no" sueltos, que no le sirven
     * a nadie.
     */
    private function validarObservacion(Request $request): array
    {
        return $request->validate([
            'observacion' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'observacion.required' => 'Escribe el motivo: es lo que va a recibir el proveedor.',
            'observacion.min' => 'El motivo es muy corto. Explica qué tiene que corregir.',
        ]);
    }

    private function empresa(Request $request): int
    {
        return (int) $request->attributes->get('id_empresa_activa');
    }
}
