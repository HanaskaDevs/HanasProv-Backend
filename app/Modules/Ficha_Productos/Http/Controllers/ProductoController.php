<?php

namespace App\Modules\Ficha_Productos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Http\Requests\GuardarProductoRequest;
use App\Modules\Ficha_Productos\Http\Requests\SubirDocumentoProductoRequest;
use App\Modules\Ficha_Productos\Http\Resources\ProductoAdminResource;
use App\Modules\Ficha_Productos\Http\Resources\ProductoResource;
use App\Modules\Ficha_Productos\Services\ProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ProductoController extends Controller
{
    public function __construct(protected ProductoService $productoService) {}

    /**
     * Búsqueda de productos entre TODOS los proveedores de la empresa
     * activa -> a diferencia de index() (que es "mis productos" para el
     * proveedor autenticado), esto es para que Sistemas/Admin pueda
     * buscar un producto sin saber de antemano a qué proveedor
     * pertenece.
     */
    public function indexTodos(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        if (! $request->user()->esSistemasGlobal()) {
            $idEmpresa = (int) $request->attributes->get('id_empresa_activa');
            if (! $request->user()->esAdmin($idEmpresa)) {
                throw new AccessDeniedHttpException('No tienes permisos para ver los productos de los proveedores.');
            }
        }

        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $productos = $this->productoService->listarTodos(
            $idEmpresaActiva,
            $request->query('search'),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 20)
        );

        return ProductoAdminResource::collection($productos);
    }

    public function guardarCodigoBC(Request $request, int $producto): JsonResponse
    {
        if (! $request->user()->esSistemasGlobal()) {
            $idEmpresa = (int) $request->attributes->get('id_empresa_activa');
            if (! $request->user()->esAdmin($idEmpresa)) {
                throw new AccessDeniedHttpException('No tienes permisos para editar el Código BC.');
            }
        }

        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $request->validate(['codigo_bc' => ['nullable', 'string', 'max:50']]);

        $productoActualizado = $this->productoService->guardarCodigoBC($idEmpresaActiva, $producto, $request->input('codigo_bc'));

        return response()->json(new ProductoAdminResource($productoActualizado));
    }

    public function importarCodigosBC(Request $request): JsonResponse
    {
        if (! $request->user()->esSistemasGlobal()) {
            $idEmpresa = (int) $request->attributes->get('id_empresa_activa');
            if (! $request->user()->esAdmin($idEmpresa)) {
                throw new AccessDeniedHttpException('No tienes permisos para importar Códigos BC.');
            }
        }

        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $request->validate(['archivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:4096']]);

        $resultado = $this->productoService->importarCodigosBC($idEmpresaActiva, $request->file('archivo'));

        return response()->json($resultado);
    }

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
            $request->file('archivo')
        );

        return response()->json($documento, 201);
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