<?php

namespace App\Modules\Auditorias\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auditorias\Services\CalificacionRecepcionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calificación de Recepciones (FGH04.15.05-1). Los permisos (Sistemas /
 * Admin / Calidad) los valida el service, no estas rutas.
 */
class CalificacionRecepcionController extends Controller
{
    public function __construct(protected CalificacionRecepcionService $servicio)
    {
    }

    private function idEmpresa(Request $request): int
    {
        return (int) $request->attributes->get('id_empresa_activa');
    }

    /** Catálogo de los 13 parámetros, para poder ver el formulario en blanco. */
    public function parametros(Request $request): JsonResponse
    {
        // Se pide el listado de proveedores solo para reusar su chequeo de
        // permisos antes de devolver el catálogo.
        $this->servicio->listarProveedores($request->user(), $this->idEmpresa($request));

        return response()->json(
            $this->servicio->listarParametros()->map(fn ($p) => [
                'id_recepcion_parametro' => $p->Id_Recepcion_Parametro,
                'orden' => $p->Orden,
                'descripcion' => $p->Descripcion,
                'puntaje' => (float) $p->Puntaje,
                'etiqueta_afirmativa' => $p->Etiqueta_Afirmativa,
                'etiqueta_negativa' => $p->Etiqueta_Negativa,
            ])->values()
        );
    }

    /** Proveedores con su fecha agendada y si les toca hoy. */
    public function proveedores(Request $request): JsonResponse
    {
        return response()->json(
            $this->servicio->listarProveedores($request->user(), $this->idEmpresa($request))
        );
    }

    public function historial(Request $request): JsonResponse
    {
        return response()->json(
            $this->servicio->listarHistorial($request->user(), $this->idEmpresa($request))
        );
    }

    /** Retoma el borrador del proveedor o arranca uno nuevo, y lo devuelve armado. */
    public function iniciar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'id_proveedor' => ['required', 'integer'],
            'fecha_recepcion' => ['nullable', 'date'],
            'contacto' => ['nullable', 'string', 'max:200'],
        ]);

        $idEmpresa = $this->idEmpresa($request);

        $calificacion = $this->servicio->obtenerOCrearBorrador(
            $request->user(),
            $idEmpresa,
            $datos['id_proveedor'],
            $datos['fecha_recepcion'] ?? null,
            $datos['contacto'] ?? null,
        );

        return response()->json(
            $this->servicio->obtenerDetalle($request->user(), $idEmpresa, $calificacion->Id_Calificacion_Recepcion)
        );
    }

    public function mostrar(Request $request, int $calificacion): JsonResponse
    {
        return response()->json(
            $this->servicio->obtenerDetalle($request->user(), $this->idEmpresa($request), $calificacion)
        );
    }

    public function actualizarCabecera(Request $request, int $calificacion): JsonResponse
    {
        $datos = $request->validate([
            'fecha_recepcion' => ['nullable', 'date'],
            'contacto' => ['nullable', 'string', 'max:200'],
        ]);

        $idEmpresa = $this->idEmpresa($request);

        $this->servicio->actualizarCabecera(
            $request->user(),
            $idEmpresa,
            $calificacion,
            $datos['fecha_recepcion'] ?? null,
            $datos['contacto'] ?? null,
        );

        return response()->json($this->servicio->obtenerDetalle($request->user(), $idEmpresa, $calificacion));
    }

    /** Autoguardado de un parámetro. Devuelve el detalle completo para refrescar el puntaje en vivo. */
    public function guardarRespuesta(Request $request, int $calificacion): JsonResponse
    {
        $datos = $request->validate([
            'id_recepcion_parametro' => ['required', 'integer'],
            'cumple' => ['required', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $idEmpresa = $this->idEmpresa($request);

        $this->servicio->guardarRespuesta(
            $request->user(),
            $idEmpresa,
            $calificacion,
            $datos['id_recepcion_parametro'],
            $datos['cumple'],
            $datos['observacion'] ?? null,
        );

        return response()->json($this->servicio->obtenerDetalle($request->user(), $idEmpresa, $calificacion));
    }

    public function finalizar(Request $request, int $calificacion): JsonResponse
    {
        $idEmpresa = $this->idEmpresa($request);

        $this->servicio->finalizar($request->user(), $idEmpresa, $calificacion);

        return response()->json($this->servicio->obtenerDetalle($request->user(), $idEmpresa, $calificacion));
    }
}
