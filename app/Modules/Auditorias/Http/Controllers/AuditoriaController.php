<?php

namespace App\Modules\Auditorias\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Services\AuditoriaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditoriaController extends Controller
{
    public function __construct(protected AuditoriaService $auditoriaService) {}

    public function tiposAuditoria(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $tipos = $this->auditoriaService->listarTiposAuditoria($request->user(), $idEmpresaActiva);

        return response()->json($tipos->map(fn ($t) => [
            'id_tipo_auditoria' => $t->Id_Tipo_Auditoria,
            'nombre' => $t->Nombre,
        ]));
    }

    public function proveedores(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        $proveedores = $this->auditoriaService->listarProveedoresParaAuditoria($request->user(), $idEmpresaActiva);

        return response()->json($proveedores);
    }

    /**
     * Retoma o crea la auditoría (Borrador) para el tipo+proveedor elegidos,
     * y devuelve el formulario completo ya armado.
     */
    public function iniciar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_tipo_auditoria' => ['required', 'integer'],
            'id_proveedor' => ['required', 'integer'],
        ]);

        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');
        $usuario = $request->user();

        $auditoria = $this->auditoriaService->obtenerOCrearAuditoria(
            $usuario,
            $idEmpresaActiva,
            $data['id_tipo_auditoria'],
            $data['id_proveedor']
        );

        return response()->json($this->auditoriaService->obtenerDetalle($usuario, $auditoria));
    }

    public function mostrar(Request $request, Auditoria $auditoria): JsonResponse
    {
        return response()->json($this->auditoriaService->obtenerDetalle($request->user(), $auditoria));
    }

    public function guardarRespuesta(Request $request, Auditoria $auditoria): JsonResponse
    {
        $data = $request->validate([
            'id_auditoria_pregunta' => ['required', 'integer'],
            'puntaje_obtenido' => ['nullable', 'numeric', 'min:0'],
            'no_aplica' => ['required', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $this->auditoriaService->guardarRespuesta(
            $request->user(),
            $auditoria,
            $data['id_auditoria_pregunta'],
            $data['puntaje_obtenido'] ?? null,
            $data['no_aplica'],
            $data['observacion'] ?? null
        );

        return response()->json($this->auditoriaService->obtenerDetalle($request->user(), $auditoria));
    }

    public function finalizar(Request $request, Auditoria $auditoria): JsonResponse
    {
        $this->auditoriaService->finalizar($request->user(), $auditoria);

        return response()->json($this->auditoriaService->obtenerDetalle($request->user(), $auditoria));
    }
}