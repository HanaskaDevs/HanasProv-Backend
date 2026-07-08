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
        return response()->json($this->documentoService->obtenerChecklist($request->user()));
    }

    public function subir(SubirDocumentoRequest $request, int $tipoDocumento): JsonResponse
    {
        $documento = $this->documentoService->subirDocumento(
            $request->user(),
            $tipoDocumento,
            $request->file('archivo'),
            $request->validated('fecha_caducidad')
        );

        return response()->json(new DocumentoProveedorResource($documento), 201);
    }

    public function descargar(Request $request, int $documentoProveedor)
    {
        return $this->documentoService->descargar($request->user(), $documentoProveedor);
    }
}