<?php

namespace App\Modules\Catalogo_Productos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogo_Productos\Http\Requests\ImportarCodigosBcRequest;
use App\Modules\Catalogo_Productos\Services\CatalogoProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de Productos: vista interna (Sistemas / Admin / Compras) de
 * todos los productos de todos los proveedores de la empresa activa,
 * con carga masiva del código de Business Central vía Excel.
 *
 * El controller queda delgado a propósito (igual que el resto de los
 * módulos): toda la lógica y las validaciones de negocio viven en
 * CatalogoProductoService.
 */
class CatalogoProductoController extends Controller
{
    public function __construct(protected CatalogoProductoService $servicio)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $idEmpresaActiva = $this->empresaActiva($request);
        $this->servicio->verificarAcceso($request->user(), $idEmpresaActiva);

        $filtros = $this->validarFiltros($request);

        return response()->json($this->servicio->listar($idEmpresaActiva, $filtros));
    }

    public function resumen(Request $request): JsonResponse
    {
        $idEmpresaActiva = $this->empresaActiva($request);
        $this->servicio->verificarAcceso($request->user(), $idEmpresaActiva);

        return response()->json($this->servicio->resumen($idEmpresaActiva));
    }

    /**
     * Devuelve las filas (sin paginar) que el navegador convierte en
     * .xlsx. Se responde JSON, no un archivo: el Excel se arma en el
     * front con SheetJS, así el backend no necesita ninguna librería de
     * Office ni generar archivos temporales.
     */
    public function exportar(Request $request): JsonResponse
    {
        $idEmpresaActiva = $this->empresaActiva($request);
        $this->servicio->verificarAcceso($request->user(), $idEmpresaActiva);

        $filtros = $this->validarFiltros($request);

        return response()->json([
            'filas' => $this->servicio->filasParaExportar($idEmpresaActiva, $filtros),
        ]);
    }

    /**
     * Paso 1 de la carga: valida el archivo y devuelve el reporte SIN
     * escribir nada, para que el usuario vea qué se va a actualizar y
     * qué filas tienen problemas antes de confirmar.
     */
    public function validarImportacion(ImportarCodigosBcRequest $request): JsonResponse
    {
        $idEmpresaActiva = $this->empresaActiva($request);
        $this->servicio->verificarAcceso($request->user(), $idEmpresaActiva);

        return response()->json($this->servicio->importarCodigosBc(
            $request->user(),
            $idEmpresaActiva,
            $request->validated()['filas'],
            soloValidar: true
        ));
    }

    /**
     * Paso 2: aplica los cambios. Las filas con error se descartan y las
     * válidas se guardan igual (importación parcial).
     */
    public function importar(ImportarCodigosBcRequest $request): JsonResponse
    {
        $idEmpresaActiva = $this->empresaActiva($request);
        $this->servicio->verificarAcceso($request->user(), $idEmpresaActiva);

        return response()->json($this->servicio->importarCodigosBc(
            $request->user(),
            $idEmpresaActiva,
            $request->validated()['filas'],
            soloValidar: false
        ));
    }

    // -------------------------------------------------------------------

    protected function empresaActiva(Request $request): int
    {
        // La deja el middleware EmpresaActiva, que ya validó que la
        // sesión tenga empresa seleccionada y que el usuario tenga acceso.
        return (int) $request->attributes->get('id_empresa_activa');
    }

    protected function validarFiltros(Request $request): array
    {
        return $request->validate([
            'busqueda' => ['nullable', 'string', 'max:150'],
            'estado' => ['nullable', Rule::in(CatalogoProductoService::ESTADOS_FILTRABLES)],
            'codigo_bc' => ['nullable', Rule::in(['con_codigo', 'sin_codigo'])],
            'pagina' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
    }
}