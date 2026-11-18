<?php

namespace App\Modules\Ficha_Productos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Http\Requests\ImportarRevisionCatalogoRequest;
use App\Modules\Ficha_Productos\Services\RevisionCatalogoExcelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Excel de los productos que esperan a Catálogo en "Productos por revisar"
 * (18-nov-2026): descargar todo lo pendiente, corregirlo en el archivo y
 * volver a subirlo.
 *
 * Controller delgado, como el resto: los permisos (Catálogo, Admin,
 * Sistemas) y todas las reglas viven en RevisionCatalogoExcelService.
 */
class RevisionCatalogoExcelController extends Controller
{
    public function __construct(protected RevisionCatalogoExcelService $excel)
    {
    }

    /**
     * Las filas (JSON) que el navegador convierte en .xlsx, más los valores
     * válidos de las columnas de lista para la hoja de ayuda.
     */
    public function exportar(Request $request): JsonResponse
    {
        return response()->json($this->excel->exportar($request->user(), $this->empresa($request)));
    }

    /** Paso 1: el reporte de qué cambiaría, SIN guardar nada. */
    public function validarImportacion(ImportarRevisionCatalogoRequest $request): JsonResponse
    {
        return response()->json($this->excel->importar(
            $request->user(),
            $this->empresa($request),
            $request->validated()['filas'],
            soloValidar: true
        ));
    }

    /** Paso 2: guarda las filas válidas; las que tienen error se descartan. */
    public function importar(ImportarRevisionCatalogoRequest $request): JsonResponse
    {
        return response()->json($this->excel->importar(
            $request->user(),
            $this->empresa($request),
            $request->validated()['filas'],
            soloValidar: false
        ));
    }

    private function empresa(Request $request): int
    {
        return (int) $request->attributes->get('id_empresa_activa');
    }
}
