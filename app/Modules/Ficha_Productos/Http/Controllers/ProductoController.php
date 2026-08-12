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
    public function __construct(protected ProductoService $productoService) {}

    public function index(Request $request)
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $productos = $this->productoService->listar(
            $request->user(),
            $idEmpresaActiva,
            $request->query('search'),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 20),
            $request->query('estado')
        );

        // Sin response()->json() a propósito: cuando el resource
        // collection envuelve un paginador, Laravel solo agrega
        // automáticamente "links"/"meta" (total, current_page,
        // last_page...) si se devuelve así, dejando que el framework
        // haga la conversión a respuesta -> response()->json() lo
        // serializaría plano, sin esa info que el front necesita para
        // pintar el paginado.
        return ProductoResource::collection($productos);
    }

    public function store(GuardarProductoRequest $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $producto = $this->productoService->crear($request->user(), $idEmpresaActiva, $request->validated());

        return response()->json(new ProductoResource($producto), 201);
    }

    public function subirDocumento(SubirDocumentoProductoRequest $request, int $producto, int $tipoDocumento): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $documento = $this->productoService->subirDocumento(
            $request->user(),
            $idEmpresaActiva,
            $producto,
            $tipoDocumento,
            $request->file('archivo'),
            $request->input('fecha_caducidad'),
            $request->input('nombre_documento')
        );

        return response()->json($documento, 201);
    }

    /**
     * Catálogo activo de tipos de documento de producto -> el front lo
     * usa para armar el checklist dinámicamente (categorías,
     * obligatoriedad, si permite varios archivos o pide fecha de
     * caducidad), en vez de tenerlo hardcodeado.
     */
    public function tiposDocumento(): JsonResponse
    {
        return response()->json(
            $this->productoService->listarTiposDocumento()->map(fn ($tipo) => [
                'id_tipo_documento_producto' => $tipo->Id_Tipo_Documento_Producto,
                'nombre_documento' => $tipo->Nombre_Documento,
                'carpeta_slug' => $tipo->Carpeta_Slug,
                'obligatorio' => (bool) $tipo->Obligatorio,
                'permite_multiples' => (bool) $tipo->Permite_Multiples,
                'requiere_fecha_caducidad' => (bool) $tipo->Requiere_Fecha_Caducidad,
            ])->values()
        );
    }

    public function descargarDocumento(Request $request, int $documentoProducto)
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        return $this->productoService->descargarDocumento($request->user(), $idEmpresaActiva, $documentoProducto);
    }

    public function resumenRegistro(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');
        $ids = $request->query('ids');
        $idsProductos = $ids ? array_map('intval', explode(',', $ids)) : null;

        return response()->json(
            $this->productoService->resumenRegistro($request->user(), $idEmpresaActiva, $idsProductos)
        );
    }

    public function registrar(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];

        $total = $this->productoService->registrar($request->user(), $idEmpresaActiva, $ids);

        return response()->json([
            'message' => "Se registraron {$total} producto(s) para calificación.",
            'total' => $total,
        ]);
    }

    public function confirmarCorreccionProducto(Request $request, int $producto): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $this->productoService->confirmarCorreccionProducto($request->user(), $idEmpresaActiva, $producto);

        return response()->json(['message' => 'Corrección registrada correctamente.']);
    }

    public function destroy(Request $request, int $producto): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $this->productoService->eliminar($request->user(), $idEmpresaActiva, $producto);

        return response()->json(['message' => 'Producto eliminado correctamente.']);
    }

    public function destroyMasivo(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];

        $total = $this->productoService->eliminarMasivo($request->user(), $idEmpresaActiva, $ids);

        return response()->json(['message' => "Se eliminaron {$total} producto(s).", 'total' => $total]);
    }

    public function destroyDocumento(Request $request, int $documentoProducto): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $this->productoService->eliminarDocumento($request->user(), $idEmpresaActiva, $documentoProducto);

        return response()->json(['message' => 'Documento eliminado correctamente.']);
    }
}