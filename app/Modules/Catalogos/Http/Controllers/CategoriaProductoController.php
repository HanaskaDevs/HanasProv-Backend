<?php

namespace App\Modules\Catalogos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Proveedores\Models\CategoriaProducto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CategoriaProductoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        return response()->json(
            CategoriaProducto::orderBy('Nombre_Categoria')
                ->get(['Id_Categoria_Producto', 'Nombre_Categoria', 'Descripcion', 'Activo'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $request->validate([
            'nombre_categoria' => ['required', 'string', 'max:150', Rule::unique('Categoria_Producto', 'Nombre_Categoria')],
            'descripcion' => ['nullable', 'string', 'max:100'],
        ]);

        $categoria = CategoriaProducto::create([
            'Nombre_Categoria' => $datos['nombre_categoria'],
            'Descripcion' => $datos['descripcion'] ?? null,
            'Activo' => true,
        ]);

        return response()->json($categoria, 201);
    }

    public function update(Request $request, CategoriaProducto $categoria): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $request->validate([
            'nombre_categoria' => [
                'required', 'string', 'max:150',
                Rule::unique('Categoria_Producto', 'Nombre_Categoria')->ignore($categoria->Id_Categoria_Producto, 'Id_Categoria_Producto'),
            ],
            'descripcion' => ['nullable', 'string', 'max:100'],
        ]);

        $categoria->update([
            'Nombre_Categoria' => $datos['nombre_categoria'],
            'Descripcion' => $datos['descripcion'] ?? null,
        ]);

        return response()->json($categoria);
    }

    public function destroy(Request $request, CategoriaProducto $categoria): JsonResponse
    {
        $this->verificarSistemas($request);

        $categoria->update(['Activo' => false]);

        return response()->json(['message' => 'Categoría de producto inactivada correctamente.']);
    }

    public function activar(Request $request, CategoriaProducto $categoria): JsonResponse
    {
        $this->verificarSistemas($request);

        $categoria->update(['Activo' => true]);

        return response()->json($categoria);
    }

    protected function verificarSistemas(Request $request): void
    {
        if (! $request->user()->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar este catálogo.');
        }
    }
}