<?php

namespace App\Modules\Ficha_Productos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Http\Requests\GuardarProductoRequest;
use App\Modules\Ficha_Productos\Http\Requests\SubirDocumentoProductoRequest;
use App\Modules\Ficha_Productos\Http\Resources\ProductoResource;
use App\Modules\Ficha_Productos\Services\ProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ficha de productos de UN proveedor, manejada por personal interno
 * (Compras -el "comprador"-, Admin y Sistemas).
 *
 * Es el espejo exacto de ProductoController, con una sola diferencia: el
 * proveedor no se deduce del usuario autenticado sino que llega en la URL.
 * Todo lo demás -las reglas de edición, el bloqueo, los documentos
 * obligatorios y el envío a calificación- es LA MISMA LLAMADA al mismo
 * ProductoService, no una copia: ver el comentario de
 * ProductoService::proveedorDeTrabajo, que es el único punto donde se
 * decide de quién son los productos.
 *
 * Decisión del usuario (10-sep-2026): el comprador pasa por el MISMO
 * CIRCUITO IDÉNTICO al del proveedor. O sea que tampoco puede mandar un
 * producto a aprobar sin sus documentos obligatorios completos; para eso
 * también puede subirlos él.
 *
 * Los permisos los verifica el Service, no estas rutas -mismo criterio que
 * el resto del portal-, así que un endpoint nuevo que se agregue acá
 * mañana nace protegido igual.
 */
class ProductosDeProveedorController extends Controller
{
    public function __construct(protected ProductoService $productoService) {}

    /**
     * Proveedores de la empresa activa para el selector, con cuántos
     * productos tiene cada uno y en qué estado.
     *
     * Los conteos van en la MISMA consulta (subconsultas correlacionadas)
     * y no una por proveedor: son datos para pintar una lista, y con
     * decenas de proveedores un conteo por fila sería una consulta por
     * cada uno cada vez que se abre la pantalla.
     */
    public function proveedores(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->productoService->verificarAccesoInterno($request->user(), $idEmpresa);

        // selectRaw y no addSelect(['alias' => DB::raw(...)]): esa segunda
        // forma es para subconsultas armadas con el query builder, y con una
        // expresión cruda pierde el alias -> la columna vuelve sin nombre y
        // el map() de abajo revienta con "Undefined property".
        $conteo = fn (string $alias, string $condicion) => "(SELECT COUNT(*) FROM Producto pr WHERE pr.Id_Proveedor = p.Id_Proveedor AND pr.Activo = 1 AND {$condicion}) as {$alias}";

        $proveedores = DB::table('Proveedor as p')
            ->leftJoin('Estado_Proveedor as e', 'e.Id_Estado_Proveedor', '=', 'p.Id_Estado_Proveedor')
            ->where('p.Id_Empresa', $idEmpresa)
            ->where('p.Activo', 1)
            ->select([
                'p.Id_Proveedor as id_proveedor',
                'p.Razon_Social as razon_social',
                'p.Nombre_Comercial as nombre_comercial',
                'p.Ruc as ruc',
                'e.Nombre_Estado as estado',
            ])
            ->selectRaw($conteo('total_productos', '1 = 1'))
            // "Pendiente" acá significa lo mismo que en la pantalla del
            // proveedor: cargado pero todavía sin mandar a calificar. Un
            // producto recién creado no tiene Estado_Calificacion todavía
            // (queda NULL, el string "Pendiente" recién se escribe al
            // registrar), así que hay que aceptar los dos.
            ->selectRaw($conteo('productos_pendientes', "pr.Bloqueado = 0 AND (pr.Estado_Calificacion IS NULL OR pr.Estado_Calificacion = 'Pendiente')"))
            ->selectRaw($conteo('productos_aprobados', "pr.Estado_Calificacion = 'Aprobado'"))
            ->selectRaw($conteo('productos_rechazados', "pr.Estado_Calificacion = 'Rechazado'"))
            ->orderBy('p.Razon_Social')
            ->get();

        return response()->json($proveedores->map(fn ($p) => [
            'id_proveedor' => (int) $p->id_proveedor,
            'razon_social' => $p->razon_social,
            'nombre_comercial' => $p->nombre_comercial,
            'ruc' => $p->ruc,
            'estado' => $p->estado,
            'total_productos' => (int) $p->total_productos,
            'productos_pendientes' => (int) $p->productos_pendientes,
            'productos_aprobados' => (int) $p->productos_aprobados,
            'productos_rechazados' => (int) $p->productos_rechazados,
        ]));
    }

    public function index(Request $request, int $proveedor)
    {
        $productos = $this->productoService->listar(
            $request->user(),
            $this->empresa($request),
            $request->query('search'),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 20),
            $request->query('estado'),
            $proveedor
        );

        return ProductoResource::collection($productos);
    }

    public function store(GuardarProductoRequest $request, int $proveedor): JsonResponse
    {
        $producto = $this->productoService->crear(
            $request->user(),
            $this->empresa($request),
            $request->validated(),
            $proveedor
        );

        return response()->json(new ProductoResource($producto), 201);
    }

    public function update(GuardarProductoRequest $request, int $proveedor, int $producto): JsonResponse
    {
        $actualizado = $this->productoService->actualizar(
            $request->user(),
            $this->empresa($request),
            $producto,
            $request->validated(),
            $proveedor
        );

        return response()->json(new ProductoResource($actualizado));
    }

    public function destroy(Request $request, int $proveedor, int $producto): JsonResponse
    {
        $this->productoService->eliminar($request->user(), $this->empresa($request), $producto, $proveedor);

        return response()->json(['message' => 'Producto eliminado correctamente.']);
    }

    public function destroyMasivo(Request $request, int $proveedor): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];

        $total = $this->productoService->eliminarMasivo($request->user(), $this->empresa($request), $ids, $proveedor);

        return response()->json(['message' => "Se eliminaron {$total} producto(s).", 'total' => $total]);
    }

    public function resumenRegistro(Request $request, int $proveedor): JsonResponse
    {
        $ids = $request->query('ids');
        $idsProductos = $ids ? array_map('intval', explode(',', $ids)) : null;

        return response()->json(
            $this->productoService->resumenRegistro($request->user(), $this->empresa($request), $idsProductos, $proveedor)
        );
    }

    public function registrar(Request $request, int $proveedor): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];

        $total = $this->productoService->registrar($request->user(), $this->empresa($request), $ids, $proveedor);

        return response()->json([
            'message' => "Se registraron {$total} producto(s) para calificación.",
            'total' => $total,
        ]);
    }

    public function confirmarCorreccionProducto(Request $request, int $proveedor, int $producto): JsonResponse
    {
        $this->productoService->confirmarCorreccionProducto($request->user(), $this->empresa($request), $producto, $proveedor);

        return response()->json(['message' => 'Corrección registrada correctamente.']);
    }

    public function subirDocumento(SubirDocumentoProductoRequest $request, int $proveedor, int $producto, int $tipoDocumento): JsonResponse
    {
        $documento = $this->productoService->subirDocumento(
            $request->user(),
            $this->empresa($request),
            $producto,
            $tipoDocumento,
            $request->file('archivo'),
            $request->input('fecha_caducidad'),
            $request->input('nombre_documento'),
            $proveedor
        );

        return response()->json($documento, 201);
    }

    public function descargarDocumento(Request $request, int $proveedor, int $documentoProducto)
    {
        return $this->productoService->descargarDocumento(
            $request->user(),
            $this->empresa($request),
            $documentoProducto,
            $proveedor
        );
    }

    public function destroyDocumento(Request $request, int $proveedor, int $documentoProducto): JsonResponse
    {
        $this->productoService->eliminarDocumento(
            $request->user(),
            $this->empresa($request),
            $documentoProducto,
            $proveedor
        );

        return response()->json(['message' => 'Documento eliminado correctamente.']);
    }

    protected function empresa(Request $request): int
    {
        return (int) $request->attributes->get('id_empresa_activa');
    }
}
