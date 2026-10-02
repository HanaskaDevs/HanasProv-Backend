<?php

namespace App\Modules\Responsables\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Responsables\Services\ResponsableProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Responsables de proveedor.
 *
 * Dos públicos muy distintos en el mismo controlador:
 *  - miResponsable(): lo consulta el PROVEEDOR desde su panel.
 *  - el resto: administración, exclusiva de Sistemas (lo valida el
 *    Service, no la ruta).
 */
class ResponsableController extends Controller
{
    public function __construct(protected ResponsableProveedorService $servicio) {}

    /** El responsable del proveedor logueado, o null si no tiene. */
    public function miResponsable(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return response()->json([
            'responsable' => $this->servicio->paraUsuario($request->user(), $idEmpresa),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json($this->servicio->listarResponsables());
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(
            $this->servicio->crearResponsable($request->user(), $request->all()),
            201
        );
    }

    public function update(Request $request, int $responsable): JsonResponse
    {
        return response()->json(
            $this->servicio->actualizarResponsable($request->user(), $responsable, $request->all())
        );
    }

    public function destroy(Request $request, int $responsable): JsonResponse
    {
        $this->servicio->eliminarResponsable($request->user(), $responsable);

        return response()->json(['message' => 'Responsable eliminado.']);
    }

    public function asignaciones(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return response()->json($this->servicio->listarAsignaciones($request->user(), $idEmpresa));
    }

    public function eliminarAsignacion(Request $request, int $asignacion): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->servicio->eliminarAsignacion($request->user(), $idEmpresa, $asignacion);

        return response()->json(['message' => 'Asignación eliminada.']);
    }

    /** Paso 1: devuelve el reporte sin guardar nada. */
    public function validarImportacion(Request $request): JsonResponse
    {
        return response()->json($this->importacion($request, soloValidar: true));
    }

    /** Paso 2: aplica. */
    public function importar(Request $request): JsonResponse
    {
        return response()->json($this->importacion($request, soloValidar: false));
    }

    protected function importacion(Request $request, bool $soloValidar): array
    {
        $datos = $request->validate([
            'filas' => ['required', 'array', 'min:1', 'max:5000'],
            'filas.*.fila' => ['required', 'integer'],
            'filas.*.codigo_bc' => ['present', 'nullable', 'string', 'max:40'],
            'filas.*.correo' => ['present', 'nullable', 'string', 'max:200'],
        ]);

        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        return $this->servicio->importar($request->user(), $idEmpresa, $datos['filas'], $soloValidar);
    }
}
