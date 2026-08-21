<?php

namespace App\Modules\Horarios_Entrega\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Services\HorarioEntregaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calendario de Horarios de Entrega de Proveedores. Los permisos
 * (lectura: Sistemas/Admin/Compras/Calidad; gestión: solo Sistemas/Admin)
 * los valida el service, no estas rutas -> mismo criterio que Auditorías.
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

    /** Seguimiento en vivo de hoy (Modo TV y pantalla de Guardia/Compras). */
    public function hoy(Request $request): JsonResponse
    {
        $clasificacion = $request->query('clasificacion');

        return response()->json(
            $this->servicio->listarDeHoy($request->user(), $this->idEmpresa($request), $clasificacion)
        );
    }

    public function marcarArribo(Request $request, HorarioEntregaProveedor $horario): JsonResponse
    {
        return response()->json(
            $this->servicio->marcarArribo($request->user(), $this->idEmpresa($request), $horario)
        );
    }

    public function marcarEntregado(Request $request, HorarioEntregaProveedor $horario): JsonResponse
    {
        return response()->json(
            $this->servicio->marcarEntregado($request->user(), $this->idEmpresa($request), $horario)
        );
    }
}
