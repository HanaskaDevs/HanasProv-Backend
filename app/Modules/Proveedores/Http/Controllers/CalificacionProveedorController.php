<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Proveedores\Http\Requests\CalificarRequest;
use App\Modules\Proveedores\Http\Resources\FichaProveedorResource;
use App\Modules\Proveedores\Services\CalificacionProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalificacionProveedorController extends Controller
{
    public function __construct(protected CalificacionProveedorService $calificacionService)
    {
    }

    public function mostrarFicha(Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $ficha = $this->calificacionService->obtenerFicha($request->user(), $idEmpresa, $proveedor);

        return response()->json(new FichaProveedorResource($ficha));
    }

    public function calificarCampoFicha(CalificarRequest $request, int $proveedor, string $campo): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $ficha = $this->calificacionService->calificarCampoFicha(
            $request->user(),
            $idEmpresa,
            $proveedor,
            $campo,
            $request->boolean('aprobado'),
            $request->validated('observacion')
        );

        return response()->json(new FichaProveedorResource($ficha));
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
}