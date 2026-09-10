<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Models\GrupoProducto;
use App\Modules\Proveedores\Http\Resources\CategoriaProductoResource;
use App\Modules\Proveedores\Http\Resources\ClaseProveedorResource;
use App\Modules\Proveedores\Models\CategoriaProducto;
use App\Modules\Proveedores\Models\ClaseProveedor;
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
}