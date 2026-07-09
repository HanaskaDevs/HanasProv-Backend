<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
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
}