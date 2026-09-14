<?php

namespace App\Modules\Catalogos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Proveedores\Models\ClaseProveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CRUD de administración para Clase_Proveedor. Antes esta tabla no
 * tenía ninguna pantalla de gestión -> solo se podía cargar con SQL
 * directo. El único consumidor que ya existía (selección de clase en
 * Mi Ficha, sección 2) sigue funcionando igual, este es un control
 * nuevo para que Sistemas pueda mantenerla sin tocar la base a mano.
 */
class ClaseProveedorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        return response()->json(
            ClaseProveedor::orderBy('Nombre_Clase')->get(['Id_Clase_Proveedor', 'Nombre_Clase', 'Icono_Url', 'Activo'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $request->validate([
            'nombre_clase' => ['required', 'string', 'max:100', Rule::unique('Clase_Proveedor', 'Nombre_Clase')],
            'icono_url' => ['nullable', 'string', 'max:300'],
        ]);

        $clase = ClaseProveedor::create([
            'Nombre_Clase' => $datos['nombre_clase'],
            'Icono_Url' => $datos['icono_url'] ?? null,
            'Activo' => true,
        ]);

        return response()->json($clase, 201);
    }

    public function update(Request $request, ClaseProveedor $clase): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $request->validate([
            'nombre_clase' => [
                'required', 'string', 'max:100',
                Rule::unique('Clase_Proveedor', 'Nombre_Clase')->ignore($clase->Id_Clase_Proveedor, 'Id_Clase_Proveedor'),
            ],
            'icono_url' => ['nullable', 'string', 'max:300'],
        ]);

        $clase->update([
            'Nombre_Clase' => $datos['nombre_clase'],
            'Icono_Url' => $datos['icono_url'] ?? null,
        ]);

        return response()->json($clase);
    }

    public function destroy(Request $request, ClaseProveedor $clase): JsonResponse
    {
        $this->verificarSistemas($request);

        // Soft-delete: nunca borrado físico -> productos/proveedores ya
        // calificados con esta clase no deben perder la referencia.
        $clase->update(['Activo' => false]);

        return response()->json(['message' => 'Clase de proveedor inactivada correctamente.']);
    }

    public function activar(Request $request, ClaseProveedor $clase): JsonResponse
    {
        $this->verificarSistemas($request);

        $clase->update(['Activo' => true]);

        return response()->json($clase);
    }

    protected function verificarSistemas(Request $request): void
    {
        if (! $request->user()->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar este catálogo.');
        }
    }
}