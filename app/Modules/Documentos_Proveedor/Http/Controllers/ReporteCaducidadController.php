<?php

namespace App\Modules\Documentos_Proveedor\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuraciones\Services\ConfiguracionService;
use App\Modules\Documentos_Proveedor\Services\ReporteCaducidadService;
use App\Modules\Documentos_Proveedor\Services\VencimientoDocumentosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reporte de caducidad de documentos. Admin, Calidad y Sistemas (lo valida
 * el service, como en el resto de los módulos).
 */
class ReporteCaducidadController extends Controller
{
    public function __construct(
        protected ReporteCaducidadService $servicio,
        protected VencimientoDocumentosService $vencimiento,
        protected ConfiguracionService $configuracion
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $documentos = $this->servicio->listar($request->user(), $idEmpresa);

        return response()->json([
            // Los tramos viajan con los datos para que la pantalla no tenga
            // que repetir los cortes (7/15/30 días) ni sus nombres: si mañana
            // se cambian, se cambian en un solo lugar.
            'tramos' => ReporteCaducidadService::TRAMOS,
            'documentos' => $documentos,
            /*
             * Estado real de la suspensión automática.
             *
             * La pantalla decía "el proveedor puede quedar suspendido" en el
             * tramo de vencidos, y eso hoy NO es cierto: la suspensión está
             * frenada hasta config('portal.suspension_documentos_desde') y
             * además depende del interruptor de Configuraciones. Quien mira
             * este reporte necesita saber si la fecha que ve es una amenaza
             * real o solamente informativa, sin ir a preguntarle a Sistemas.
             */
            'suspension' => [
                'activa' => $this->configuracion->obtenerSuspensionAutomatica(),
                'ya_es_exigible' => $this->vencimiento->suspensionYaEsExigible(),
                'vigente_desde' => $this->vencimiento->suspensionVigenteDesde()->toDateString(),
                'dias_gracia' => VencimientoDocumentosService::DIAS_GRACIA_SUSPENSION,
                'dias_primer_aviso' => VencimientoDocumentosService::DIAS_PRIMER_AVISO,
            ],
            // Catálogo de tipos que EFECTIVAMENTE aparecen en el reporte, para
            // llenar el filtro sin que la pantalla tenga que recorrer las
            // filas ni pedir el catálogo completo (que incluye tipos que no
            // caducan y nunca saldrían acá).
            'tipos_documento' => collect($documentos)
                ->unique('id_tipo_documento')
                ->map(fn (array $d) => [
                    'id_tipo_documento' => $d['id_tipo_documento'],
                    'nombre' => $d['documento'],
                    'categoria' => $d['categoria'],
                ])
                ->sortBy('nombre')
                ->values(),
        ]);
    }
}
