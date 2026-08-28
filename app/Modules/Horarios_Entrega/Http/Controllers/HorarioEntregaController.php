<?php

namespace App\Modules\Horarios_Entrega\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Services\HorarioEntregaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calendario de Horarios de Entrega de Proveedores. Los permisos
 * (lectura: Sistemas/Admin/Compras/Calidad; gestión: solo Sistemas/Admin;
 * cambios de estado: Guardia/Sistemas/Calidad; resolver solicitudes de
 * aprobación: solo Calidad/Sistemas) los valida el service, no estas
 * rutas -> mismo criterio que Auditorías.
 */
class HorarioEntregaController extends Controller
{
    public function __construct(protected HorarioEntregaService $servicio)
    {
    }

    private function idEmpresa(Request $request): int
    {
        return (int) $request->attributes->get('id_empresa_activa');
    }

    public function index(Request $request): JsonResponse
    {
        $clasificacion = $request->query('clasificacion');

        return response()->json(
            $this->servicio->listar($request->user(), $this->idEmpresa($request), $clasificacion)
        );
    }

    /** Calendario propio del proveedor logueado (sección Pedidos). */
    public function mios(Request $request): JsonResponse
    {
        return response()->json(
            $this->servicio->misHorarios($request->user(), $this->idEmpresa($request))
        );
    }

    public function proveedores(Request $request): JsonResponse
    {
        return response()->json(
            $this->servicio->proveedoresDisponibles($request->user(), $this->idEmpresa($request))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $horario = $this->servicio->crear($request->user(), $this->idEmpresa($request), $request->all());

        return response()->json($horario, 201);
    }

    public function update(Request $request, HorarioEntregaProveedor $horario): JsonResponse
    {
        $horario = $this->servicio->actualizar($request->user(), $this->idEmpresa($request), $horario, $request->all());

        return response()->json($horario);
    }

    public function destroy(Request $request, HorarioEntregaProveedor $horario): JsonResponse
    {
        $this->servicio->eliminar($request->user(), $this->idEmpresa($request), $horario);

        return response()->json(['message' => 'Horario eliminado correctamente.']);
    }

    /** Seguimiento en vivo de hoy (Modo TV y pantalla de Guardia/Calidad). */
    public function hoy(Request $request): JsonResponse
    {
        $clasificacion = $request->query('clasificacion');

        return response()->json(
            $this->servicio->listarDeHoy($request->user(), $this->idEmpresa($request), $clasificacion)
        );
    }

    /** Pedidos que ese proveedor debe entregar HOY (modal de seguimiento). */
    public function pedidosDelDia(Request $request, HorarioEntregaProveedor $horario): JsonResponse
    {
        return response()->json(
            $this->servicio->pedidosDelDia($request->user(), $this->idEmpresa($request), $horario)
        );
    }

    public function marcarArribo(Request $request, HorarioEntregaProveedor $horario): JsonResponse
    {
        return response()->json(
            $this->servicio->marcarArribo($request->user(), $this->idEmpresa($request), $horario)
        );
    }

    /** El Guardia pide aprobación de un arribo tardío (horario Rechazado). */
    public function solicitarAprobacion(Request $request, HorarioEntregaProveedor $horario): JsonResponse
    {
        $solicitud = $this->servicio->solicitarAprobacion($request->user(), $this->idEmpresa($request), $horario);

        return response()->json($solicitud, 201);
    }

    /** Calidad: solicitudes de arribo pendientes de la empresa activa. */
    public function solicitudesPendientes(Request $request): JsonResponse
    {
        return response()->json(
            $this->servicio->listarSolicitudesPendientes($request->user(), $this->idEmpresa($request))
        );
    }

    public function aprobarSolicitud(Request $request, int $solicitud): JsonResponse
    {
        $resultado = $this->servicio->aprobarSolicitud($request->user(), $this->idEmpresa($request), $solicitud);

        return response()->json($resultado);
    }

    public function rechazarSolicitud(Request $request, int $solicitud): JsonResponse
    {
        $resultado = $this->servicio->rechazarSolicitud(
            $request->user(),
            $this->idEmpresa($request),
            $solicitud,
            $request->input('motivo')
        );

        return response()->json($resultado);
    }
}
