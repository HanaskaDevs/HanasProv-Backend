<?php

namespace App\Modules\Configuraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use App\Modules\Documentos_Proveedor\Services\VencimientoDocumentosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Interruptor de la suspensión automática de proveedores por documentación
 * vencida. Solo rol Sistemas (lo valida el service).
 */
class SuspensionDocumentosController extends Controller
{
    public function __construct(protected ConfiguracionService $configuracionService)
    {
    }

    public function show(VencimientoDocumentosService $servicio): JsonResponse
    {
        return response()->json([
            'activa' => $this->configuracionService->obtenerSuspensionAutomatica(),
            // Se devuelven los plazos para que la pantalla los muestre en vez
            // de tenerlos escritos a mano en el frontend: si mañana cambian,
            // cambian en un solo lugar.
            'dias_primer_aviso' => VencimientoDocumentosService::DIAS_PRIMER_AVISO,
            'dias_entre_avisos' => VencimientoDocumentosService::DIAS_ENTRE_AVISOS,
            'dias_gracia' => VencimientoDocumentosService::DIAS_GRACIA_SUSPENSION,
            // Candado por fecha: hasta el 31-dic-2026 se avisa pero no se
            // suspende. Se expone para que la pantalla lo explique: si no,
            // Sistemas ve el interruptor encendido y a nadie suspendido, y
            // parece que está roto.
            'vigente_desde' => $servicio->suspensionVigenteDesde()->toDateString(),
            'ya_es_exigible' => $servicio->suspensionYaEsExigible(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $datos = $request->validate(['activa' => ['required', 'boolean']]);

        $this->configuracionService->definirSuspensionAutomatica($request->user(), $datos['activa']);

        return $this->show(app(VencimientoDocumentosService::class));
    }
}
