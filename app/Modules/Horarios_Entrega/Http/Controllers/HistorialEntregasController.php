<?php

namespace App\Modules\Horarios_Entrega\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Services\HistorialEntregasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reporte "Historial de proveedores" (12-nov-2026): qué entregó cada
 * proveedor, a qué hora estaba citado, a qué hora llegó y cuánto se
 * atrasó, hasta 3 meses atrás.
 */
class HistorialEntregasController extends Controller
{
    public function __construct(protected HistorialEntregasService $historial) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'id_proveedor' => ['nullable', 'integer'],
            'clasificacion' => ['nullable', Rule::in(HorarioEntregaProveedor::CLASIFICACIONES)],
        ]);

        return response()->json($this->historial->consultar(
            $request->user(),
            (int) $request->attributes->get('id_empresa_activa'),
            $filtros
        ));
    }
}
