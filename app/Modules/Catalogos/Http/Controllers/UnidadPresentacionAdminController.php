<?php

namespace App\Modules\Catalogos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UnidadPresentacionAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        return response()->json(UnidadPresentacion::orderBy('Nombre_Unidad')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $request->validate([
            'nombre_unidad' => ['required', 'string', 'max:50', Rule::unique('Unidad_Presentacion', 'Nombre_Unidad')],
        ]);

        $unidad = UnidadPresentacion::create([
            'Nombre_Unidad' => $datos['nombre_unidad'],
            'Activo' => true,
        ]);

        return response()->json($unidad, 201);
    }

    public function update(Request $request, UnidadPresentacion $unidadPresentacion): JsonResponse
    {
        $this->verificarSistemas($request);

        $datos = $request->validate([
            'nombre_unidad' => [
                'required', 'string', 'max:50',
                Rule::unique('Unidad_Presentacion', 'Nombre_Unidad')->ignore($unidadPresentacion->Id_Unidad_Presentacion, 'Id_Unidad_Presentacion'),
            ],
        ]);

        $unidadPresentacion->update(['Nombre_Unidad' => $datos['nombre_unidad']]);

        return response()->json($unidadPresentacion);
    }

    public function destroy(Request $request, UnidadPresentacion $unidadPresentacion): JsonResponse
    {
        $this->verificarSistemas($request);

        $unidadPresentacion->update(['Activo' => false]);

        return response()->json(['message' => 'Unidad de presentación inactivada correctamente.']);
    }

    public function activar(Request $request, UnidadPresentacion $unidadPresentacion): JsonResponse
    {
        $this->verificarSistemas($request);

        $unidadPresentacion->update(['Activo' => true]);

        return response()->json($unidadPresentacion);
    }

    protected function verificarSistemas(Request $request): void
    {
        if (! $request->user()->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar este catálogo.');
        }
    }
}