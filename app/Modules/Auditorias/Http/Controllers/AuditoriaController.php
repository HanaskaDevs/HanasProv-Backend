<?php

namespace App\Modules\Auditorias\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Services\AuditoriaService;
use App\Modules\Auditorias\Services\ImagenObservacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $idTipoAuditoria = $request->query('id_tipo_auditoria');

        $proveedores = $this->auditoriaService->listarProveedoresParaAuditoria(
            $request->user(),
            $idEmpresaActiva,
            $idTipoAuditoria !== null ? (int) $idTipoAuditoria : null
        );

        return response()->json($proveedores);
    }

    /** Resumen para el panel de bienvenida de Calidad. */
    public function resumen(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        return response()->json(
            $this->auditoriaService->resumenDashboard($request->user(), $idEmpresaActiva)
        );
    }

    /** Mini reporte: la última auditoría de cada proveedor auditado. */
    public function reporte(Request $request): JsonResponse
    {
        $idEmpresaActiva = (int) $request->attributes->get('id_empresa_activa');

        return response()->json(
            $this->auditoriaService->reporteUltimasPorProveedor($request->user(), $idEmpresaActiva)
        );
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

    /**
     * Sube UNA foto a la observación de una pregunta (12-nov-2026).
     *
     * Una por llamada y no un arreglo: el formulario sube la foto apenas
     * se elige, así la persona ve enseguida si entró o si el servidor la
     * rechazó, en vez de descubrirlo al final junto con las demás.
     */
    public function subirImagen(Request $request, Auditoria $auditoria): JsonResponse
    {
        $data = $request->validate([
            'id_auditoria_pregunta' => ['required', 'integer'],
            'imagen' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . ImagenObservacionService::MAX_KB],
        ]);

        return response()->json($this->auditoriaService->subirImagenObservacion(
            $request->user(),
            $auditoria,
            $data['id_auditoria_pregunta'],
            $request->file('imagen')
        ), 201);
    }

    public function eliminarImagen(Request $request, int $imagen): JsonResponse
    {
        $this->auditoriaService->eliminarImagenObservacion($request->user(), $imagen);

        return response()->json(['eliminada' => true]);
    }

    /**
     * La foto en sí. Autenticada: el repositorio no es público.
     *
     * Recibe el id como `int` y NO como modelo tipado: el binding implícito
     * de rutas corre ANTES del middleware de autenticación, así que con un
     * modelo el endpoint contestaba 404 (en vez de 401) a quien no tenía
     * sesión. Es además como resuelve esto el resto del proyecto
     * (ReclamoController::verImagen).
     */
    public function verImagen(Request $request, int $imagen): BinaryFileResponse
    {
        return response()->file($this->auditoriaService->rutaImagenObservacion($request->user(), $imagen));
    }

    public function finalizar(Request $request, Auditoria $auditoria): JsonResponse
    {
        $this->auditoriaService->finalizar($request->user(), $auditoria);

        return response()->json($this->auditoriaService->obtenerDetalle($request->user(), $auditoria));
    }
}