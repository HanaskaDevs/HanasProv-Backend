<?php

namespace App\Modules\Documentos_Proveedor\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documentos_Proveedor\Http\Requests\SubirDocumentoRequest;
use App\Modules\Documentos_Proveedor\Http\Resources\DocumentoProveedorResource;
use App\Modules\Documentos_Proveedor\Services\DocumentoProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentoProveedorController extends Controller
{
    public function __construct(protected DocumentoProveedorService $documentoService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return response()->json($this->documentoService->obtenerChecklist($request->user(), $idEmpresa));
    }

    public function subir(SubirDocumentoRequest $request, int $tipoDocumento): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $documento = $this->documentoService->subirDocumento(
            $request->user(),
            $idEmpresa,
            $tipoDocumento,
            $request->file('archivo'),
            $request->validated('fecha_caducidad'),
            $request->validated('nombre_documento')
        );

        return response()->json(new DocumentoProveedorResource($documento), 201);
    }

    public function descargar(Request $request, int $documentoProveedor)
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return $this->documentoService->descargar($request->user(), $idEmpresa, $documentoProveedor);
    }

    public function registrar(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->documentoService->registrar($request->user(), $idEmpresa);

        return response()->json(['message' => 'Documentación registrada correctamente.']);
    }

    public function confirmarCorrecciones(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->documentoService->confirmarCorrecciones($request->user(), $idEmpresa);

        return response()->json(['message' => 'Documentación actualizada registrada correctamente.']);
    }

    public function reemplazar(SubirDocumentoRequest $request, int $documentoProveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $documento = $this->documentoService->reemplazarDocumento(
            $request->user(),
            $idEmpresa,
            $documentoProveedor,
            $request->file('archivo'),
            $request->validated('fecha_caducidad'),
            $request->validated('nombre_documento')
        );

        return response()->json(new DocumentoProveedorResource($documento), 201);
    }

    public function borrar(Request $request, int $documentoProveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->documentoService->borrarDocumento($request->user(), $idEmpresa, $documentoProveedor);

        return response()->json(['message' => 'Documento eliminado correctamente.']);
    }
}