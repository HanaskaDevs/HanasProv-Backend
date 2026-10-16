<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Proveedores\Http\Requests\CalificarFichaGeneralRequest;
use App\Modules\Proveedores\Http\Requests\CalificarRequest;
use App\Modules\Proveedores\Http\Requests\GuardarSeccion1Request;
use App\Modules\Proveedores\Http\Requests\GuardarSeccion2Request;
use App\Modules\Proveedores\Http\Requests\GuardarSeccion3Request;
use App\Modules\Proveedores\Http\Resources\FichaProveedorResource;
use App\Modules\Proveedores\Services\CalificacionProveedorService;
use App\Modules\Proveedores\Services\FichaProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalificacionProveedorController extends Controller
{
    public function __construct(
        protected CalificacionProveedorService $calificacionService,
        protected FichaProveedorService $fichaService
    ) {
    }

    public function mostrarFicha(Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $ficha = $this->calificacionService->obtenerFicha($request->user(), $idEmpresa, $proveedor);

        return response()->json(new FichaProveedorResource($ficha));
    }

    public function calificarFichaGeneral(CalificarFichaGeneralRequest $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $ficha = $this->calificacionService->calificarFichaGeneral(
            $request->user(),
            $idEmpresa,
            $proveedor,
            $request->boolean('aprobado'),
            $request->validated('campos_rechazados', [])
        );

        return response()->json(new FichaProveedorResource($ficha));
    }

    /*
     * Edición interna de la ficha (Compras y quienes califican la ficha),
     * mientras el proveedor no esté aprobado. Mismas validaciones que
     * cuando la llena el proveedor; 'acepta_politicas' se ignora (ver
     * FichaProveedorService::editarSeccion1ComoInterno). Devuelven la
     * ficha igual que mostrarFicha, para que la pantalla la reemplace.
     */

    public function editarFichaSeccion1(GuardarSeccion1Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');
        $datos = collect($request->validated())->except('acepta_politicas')->all();

        $this->fichaService->editarSeccion1ComoInterno($request->user(), $idEmpresa, $proveedor, $datos);

        return $this->mostrarFicha($request, $proveedor);
    }

    public function editarFichaSeccion2(GuardarSeccion2Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->fichaService->editarSeccion2ComoInterno($request->user(), $idEmpresa, $proveedor, $request->validated('id_clases'));

        return $this->mostrarFicha($request, $proveedor);
    }

    public function editarFichaSeccion3(GuardarSeccion3Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->fichaService->editarSeccion3ComoInterno($request->user(), $idEmpresa, $proveedor, $request->validated('id_categorias'));

        return $this->mostrarFicha($request, $proveedor);
    }

    public function mostrarDocumentos(Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return response()->json(
            $this->calificacionService->obtenerChecklistDocumentos($request->user(), $idEmpresa, $proveedor)
        );
    }

    public function calificarDocumento(CalificarRequest $request, int $documentoProveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $documento = $this->calificacionService->calificarDocumento(
            $request->user(),
            $idEmpresa,
            $documentoProveedor,
            $request->boolean('aprobado'),
            $request->validated('observacion')
        );

        return response()->json([
            'id_documento_proveedor' => $documento->Id_Documento_Proveedor,
            'estado_calificacion' => $documento->Estado_Calificacion,
            'comentario_calificacion' => $documento->Comentario_Calificacion,
            'fecha_calificacion' => $documento->Fecha_Calificacion?->toIso8601String(),
        ]);
    }

    public function verDocumento(Request $request, int $documentoProveedor)
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return $this->calificacionService->verDocumentoInline($request->user(), $idEmpresa, $documentoProveedor);
    }

    public function registrarCalificacionDocumentos(Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->calificacionService->registrarCalificacionDocumentos($request->user(), $idEmpresa, $proveedor);

        return response()->json(['message' => 'Calificación de documentos registrada correctamente.']);
    }

    public function mostrarProductos(Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return response()->json(
            $this->calificacionService->obtenerProductosCalificacion($request->user(), $idEmpresa, $proveedor)
        );
    }

    public function calificarProducto(CalificarRequest $request, int $producto): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $productoActualizado = $this->calificacionService->calificarProducto(
            $request->user(),
            $idEmpresa,
            $producto,
            $request->boolean('aprobado'),
            $request->validated('observacion')
        );

        return response()->json([
            'id_producto' => $productoActualizado->Id_Producto,
            'estado_calificacion' => $productoActualizado->Estado_Calificacion,
            'comentario_calificacion' => $productoActualizado->Comentario_Calificacion,
            'fecha_calificacion' => $productoActualizado->Fecha_Calificacion?->toIso8601String(),
        ]);
    }

    public function verDocumentoProducto(Request $request, int $documentoProducto)
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return $this->calificacionService->verDocumentoProductoInline($request->user(), $idEmpresa, $documentoProducto);
    }

    public function registrarCalificacionProductos(Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->calificacionService->registrarCalificacionProductos($request->user(), $idEmpresa, $proveedor);

        return response()->json(['message' => 'Calificación de productos registrada correctamente.']);
    }
}