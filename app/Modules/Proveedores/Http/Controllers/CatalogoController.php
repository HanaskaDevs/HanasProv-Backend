<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Models\GrupoProducto;
use App\Modules\Proveedores\Http\Resources\CategoriaProductoResource;
use App\Modules\Proveedores\Http\Resources\ClaseProveedorResource;
use App\Modules\Proveedores\Models\Banco;
use App\Modules\Proveedores\Models\CategoriaProducto;
use App\Modules\Proveedores\Models\ClaseProveedor;
use App\Modules\Proveedores\Models\GrupoImpuestoBC;
use Illuminate\Http\JsonResponse;

/**
 * Catálogos globales (no dependen de empresa) usados en los multi-select
 * de la Ficha de Proveedor (Sección 2 y 3).
 */
class CatalogoController extends Controller
{
    public function clasesProveedor(): JsonResponse
    {
        $clases = ClaseProveedor::where('Activo', true)->orderBy('Nombre_Clase')->get();

        return response()->json(ClaseProveedorResource::collection($clases));
    }

    public function categoriasProducto(): JsonResponse
    {
        $categorias = CategoriaProducto::where('Activo', true)->orderBy('Nombre_Categoria')->get();

        return response()->json(CategoriaProductoResource::collection($categorias));
    }

    /**
     * Grupos de producto activos, para el multi-select de la ficha del
     * producto. Lo consumen las DOS pantallas que editan un producto: la
     * del propio proveedor (Ficha Productos) y la del comprador
     * (Productos por proveedor) -> por eso vive acá, entre los catálogos
     * globales, y no colgando de /mis-productos, que es exclusivo del
     * usuario externo.
     *
     * Solo los ACTIVOS: un grupo que Sistemas dio de baja no se puede
     * volver a elegir, pero los productos que ya lo tenían lo conservan
     * (ver GrupoProductoController::destroy).
     */
    public function gruposProducto(): JsonResponse
    {
        $grupos = GrupoProducto::where('Activo', true)
            ->orderBy('Orden')
            ->orderBy('Codigo')
            ->get(['Id_Grupo_Producto', 'Codigo', 'Nombre', 'Descripcion']);

        // Se renombran las claves a snake_case en vez de devolver el modelo
        // crudo: las columnas de esta base son PascalCase, pero TODO lo que
        // consume el frontend viene en snake_case (ver ProductoResource, y el
        // 'grupos' que este mismo catálogo alimenta). Devolver los atributos
        // tal cual obligaría a la pantalla a manejar dos convenciones para el
        // mismo dato según de qué endpoint venga.
        return response()->json($grupos->map(fn (GrupoProducto $grupo) => [
            'id_grupo_producto' => $grupo->Id_Grupo_Producto,
            'codigo' => $grupo->Codigo,
            'nombre' => $grupo->Nombre,
            'descripcion' => $grupo->Descripcion,
        ]));
    }

    /**
     * Clase de contribuyente de la Sección 1 de la ficha. Se devuelve el
     * Codigo Y la Descripcion: el front MUESTRA la descripción pero
     * envía el código, que es el valor que BC espera en su campo "Grupo
     * de impuesto" (LHCGrupoImpuesto en Ficha_proveedor_Excel).
     */
    public function gruposImpuesto(): JsonResponse
    {
        $grupos = GrupoImpuestoBC::where('Activo', true)
            ->orderBy('Descripcion')
            ->get(['Codigo', 'Descripcion']);

        return response()->json($grupos->map(fn (GrupoImpuestoBC $g) => [
            'codigo' => $g->Codigo,
            'descripcion' => $g->Descripcion,
        ]));
    }

    /**
     * Bancos para el selector de la cuenta bancaria. A propósito NO se
     * expone Codigo_BC: es un dato interno de la integración con BC (va
     * en Bank_Branch_No), el proveedor solo elige por nombre y el código
     * viaja solo al postear.
     */
    public function bancos(): JsonResponse
    {
        $bancos = Banco::where('Activo', true)
            ->orderBy('Nombre_Banco')
            ->get(['Id_Banco', 'Nombre_Banco']);

        return response()->json($bancos->map(fn (Banco $b) => [
            'id_banco' => $b->Id_Banco,
            'nombre_banco' => $b->Nombre_Banco,
        ]));
    }
}
