<?php

namespace App\Modules\Reclamos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Reclamos\Services\HistorialReclamosService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Reporte "Historial de reclamos" (12-nov-2026): consulta por proveedor y
 * descarga en PDF de la conversación completa, con imágenes.
 *
 * Dos PDF distintos a propósito (pedido del usuario): uno por reclamo,
 * para mandar un caso puntual, y uno con todo el historial del proveedor,
 * para una reunión o una auditoría. El segundo puede pesar bastante si el
 * proveedor tiene muchas fotos: van embebidas (ver el service).
 */
class HistorialReclamosController extends Controller
{
    public function __construct(protected HistorialReclamosService $historial) {}

    protected function idEmpresa(Request $request): int
    {
        return (int) $request->attributes->get('id_empresa_activa');
    }

    /** Proveedores que tienen al menos un reclamo. */
    public function proveedores(Request $request): JsonResponse
    {
        return response()->json(
            $this->historial->proveedoresConReclamos($request->user(), $this->idEmpresa($request))
        );
    }

    /** Historial completo de un proveedor (conversaciones incluidas). */
    public function porProveedor(Request $request, int $proveedor): JsonResponse
    {
        return response()->json(
            $this->historial->historialDeProveedor($request->user(), $this->idEmpresa($request), $proveedor)
        );
    }

    /** PDF de UN reclamo. */
    public function pdfReclamo(Request $request, int $reclamo)
    {
        $modelo = $this->historial->reclamoDeLaEmpresa($request->user(), $this->idEmpresa($request), $reclamo);

        $pdf = Pdf::loadView('pdf.historial-reclamos', [
            'proveedor' => [
                'razon_social' => $modelo->proveedor?->Razon_Social,
                'nombre_comercial' => $modelo->proveedor?->Nombre_Comercial,
                'ruc' => $modelo->proveedor?->Ruc,
            ],
            'reclamos' => [$this->historial->datosParaPdf($modelo)],
            'fechaGeneracion' => now()->format('d/m/Y H:i'),
            'generadoPor' => $request->user()->Nombre_Completo,
        ]);

        return $pdf->download('reclamo-' . $modelo->Id_Reclamo . '-' . $this->sufijo($modelo->proveedor?->Razon_Social) . '.pdf');
    }

    /** PDF con TODOS los reclamos del proveedor. */
    public function pdfProveedor(Request $request, int $proveedor)
    {
        $idEmpresa = $this->idEmpresa($request);
        $this->historial->verificarAcceso($request->user(), $idEmpresa);

        $modeloProveedor = Proveedor::where('Id_Empresa', $idEmpresa)->findOrFail($proveedor);
        $reclamos = $this->historial->reclamosDelProveedor($idEmpresa, $proveedor);

        if ($reclamos->isEmpty()) {
            abort(404, 'Este proveedor no tiene reclamos registrados.');
        }

        $pdf = Pdf::loadView('pdf.historial-reclamos', [
            'proveedor' => [
                'razon_social' => $modeloProveedor->Razon_Social,
                'nombre_comercial' => $modeloProveedor->Nombre_Comercial,
                'ruc' => $modeloProveedor->Ruc,
            ],
            'reclamos' => $reclamos->map(fn ($r) => $this->historial->datosParaPdf($r))->all(),
            'fechaGeneracion' => now()->format('d/m/Y H:i'),
            'generadoPor' => $request->user()->Nombre_Completo,
        ]);

        return $pdf->download('historial-reclamos-' . $this->sufijo($modeloProveedor->Razon_Social) . '.pdf');
    }

    /** Nombre de archivo sin tildes ni espacios. */
    protected function sufijo(?string $razonSocial): string
    {
        return Str::slug(Str::limit($razonSocial ?? 'proveedor', 40, '')) ?: 'proveedor';
    }
}
